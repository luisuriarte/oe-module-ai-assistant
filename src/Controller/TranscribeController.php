<?php

/**
 * TranscribeController — handles audio uploads, background transcription, and polling.
 *
 * Implements the asynchronous submit-and-poll flow for Whisper transcription:
 *   - Concurrency: Host-shared non-blocking flock() on normalized Whisper URL hash.
 *   - Security: Session, CSRF, ACL ('use' for clinical, 'admin' for test mode).
 *   - Validation: Real content inspection (finfo + magic bytes), size limit.
 *   - Storage: Temp audio and transcripts stored with 0600 permissions outside webroot.
 *   - Ephemeral: Transcript deleted on first successful delivery; audio deleted in finally.
 *   - Audit: Strictly metadata-only; no clinical content in errors or audit logs.
 *
 * Compatibility: OpenEMR 8.2.0+ (PHP 8.2 compatible).
 *
 * @package   OpenEMR
 * @subpackage AiAssistant
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\AiAssistant\Controller;

use finfo;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\AiAssistant\Audit\AuditLogger;
use OpenEMR\Modules\AiAssistant\Settings\SettingsManager;
use OpenEMR\Modules\AiAssistant\Transcription\TranscriptionClient;

class TranscribeController
{
    private SettingsManager $settings;
    private AuditLogger $audit;
    private SystemLogger $logger;

    public function __construct()
    {
        $this->settings = new SettingsManager();
        $this->audit    = new AuditLogger();
        $this->logger   = new SystemLogger();
    }

    // -------------------------------------------------------------------------
    // 1. Submit Endpoint: action=transcribe_submit (POST)
    // -------------------------------------------------------------------------

    public function submit(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'method_not_allowed']);
            return;
        }

        // 1. CSRF Validation
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $token   = $_POST['csrf_token'] ?? $_POST['csrf_token_form'] ?? '';
        if (!CsrfUtils::verifyCsrfToken($token, $session)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_csrf']);
            return;
        }

        $userId = (int) ($_SESSION['authUserID'] ?? 0);
        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode(['error' => 'unauthorized']);
            return;
        }

        // 2. Check mode: test mode (admin-only) vs clinical mode (use permission)
        $isTest = !empty($_POST['test_mode']);
        if ($isTest) {
            if (!AclMain::aclCheckCore('ai_assistant', 'admin')) {
                http_response_code(403);
                echo json_encode(['error' => 'access_denied']);
                return;
            }
            $pid       = 0;
            $encounter = 0;
        } else {
            if (!AclMain::aclCheckCore('ai_assistant', 'use')) {
                http_response_code(403);
                echo json_encode(['error' => 'access_denied']);
                return;
            }
            $pid       = (int) ($_POST['pid'] ?? 0);
            $encounter = (int) ($_POST['encounter'] ?? 0);

            // Validate clinical context against database
            if ($pid <= 0 || $encounter <= 0 || !$this->validateClinicalContext($pid, $encounter)) {
                http_response_code(400);
                echo json_encode(['error' => 'invalid_clinical_context']);
                return;
            }
        }

        // 3. Check Whisper server configuration
        $whisperUrl = $this->settings->get('whisper_url', 'http://127.0.0.1:8178');
        try {
            $normalizedWhisperUrl = TranscriptionClient::validateAndNormalizeUrl($whisperUrl);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'invalid_whisper_configuration']);
            return;
        }

        // 4. File upload validation
        if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'no_audio_file']);
            return;
        }

        $uploadedTmp = $_FILES['audio']['tmp_name'];
        if (!is_uploaded_file($uploadedTmp)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_upload']);
            return;
        }

        // Timeout & size formulas derived from maximum audio duration setting
        $maxAudioSec = (int) $this->settings->get('whisper_max_audio_sec', 180);
        if ($maxAudioSec < 30) {
            $maxAudioSec = 30;
        }

        // Max file size: generous bound based on 320 kbps uncompressed peak audio + overhead (approx 40KB/s)
        $maxBytes = max(10 * 1024 * 1024, (int) ($maxAudioSec * 45000));
        $fileSize = (int) filesize($uploadedTmp);
        if ($fileSize <= 0 || $fileSize > $maxBytes) {
            http_response_code(413);
            echo json_encode(['error' => 'file_too_large']);
            return;
        }

        // Real content inspection (MIME type + magic bytes)
        if (!$this->validateAudioContent($uploadedTmp)) {
            http_response_code(415);
            echo json_encode(['error' => 'unsupported_audio_format']);
            return;
        }

        // 5. Concurrency Control: Exclusive non-blocking flock() on host-shared lock file
        // Keyed by SHA1 of the normalized Whisper URL so all sites/instances sharing this server synchronize
        $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oe_whisper_' . sha1($normalizedWhisperUrl) . '.lock';
        $lockFp   = @fopen($lockFile, 'c+');
        if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            if ($lockFp) {
                fclose($lockFp);
            }
            // Return HTTP 429 with JSON busy response as required
            http_response_code(429);
            echo json_encode([
                'error'   => 'busy',
                'message' => xlt('The transcription engine is currently processing another audio. Please try again shortly.'),
            ]);
            return;
        }

        // 6. Setup Secure Job Directory outside web root
        $jobDir = $this->getSecureJobDir();
        $this->cleanOrphanFiles($jobDir);

        // Move uploaded file to secure storage with 0600 permissions
        $tempAudioPath = $jobDir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.tmp';
        if (!move_uploaded_file($uploadedTmp, $tempAudioPath)) {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            http_response_code(500);
            echo json_encode(['error' => 'storage_error']);
            return;
        }
        @chmod($tempAudioPath, 0600);

        // Generate unique Job ID
        $jobId = bin2hex(random_bytes(16));

        // Derive timeouts from single formula based on max audio duration:
        // - Whisper HTTP inference timeout: max_audio_sec * 1.5 + 30s
        // - Worker execution timeout: inferenceTimeout + 30s
        // - Polling job TTL: workerTimeout + 600s (~10 minutes)
        $inferenceTimeout = max(30, (int) round($maxAudioSec * 1.5 + 30));
        $workerTimeout    = $inferenceTimeout + 30;
        $jobTtl           = max(600, $workerTimeout + 300);

        // Write initial job metadata file (0600 permissions)
        $jobMeta = [
            'job_id'       => $jobId,
            'user_id'      => $userId,
            'patient_id'   => $pid,
            'encounter_id' => $encounter,
            'is_test'      => $isTest,
            'status'       => 'processing',
            'created_at'   => time(),
            'expires_at'   => time() + $jobTtl,
            'heartbeat'    => time(),
            'duration_ms'  => 0,
            'error_code'   => '',
        ];
        $this->saveJobMeta($jobDir, $jobId, $jobMeta);

        // 7. Release PHP Session lock immediately before background work
        session_write_close();

        // 8. If FastCGI is available, flush 202 Accepted response to the client immediately
        $sentAsync = false;
        if (function_exists('fastcgi_finish_request')) {
            http_response_code(202);
            header('Content-Type: application/json');
            echo json_encode([
                'status'  => 'processing',
                'job_id'  => $jobId,
                'timeout' => $inferenceTimeout,
            ]);
            fastcgi_finish_request();
            $sentAsync = true;
        }

        // 9. Execute worker transcription (holds lock until complete or process terminates)
        $this->runTranscriptionWorker(
            $jobDir,
            $jobId,
            $tempAudioPath,
            $normalizedWhisperUrl,
            $inferenceTimeout,
            $workerTimeout,
            $lockFp,
            $lockFile,
            $userId,
            $pid,
            $encounter,
            $isTest
        );

        // If not sent via fastcgi_finish_request, output response synchronously
        if (!$sentAsync) {
            $finalMeta = $this->readJobMeta($jobDir, $jobId);
            if (($finalMeta['status'] ?? '') === 'completed') {
                http_response_code(200);
                echo json_encode([
                    'status'      => 'completed',
                    'job_id'      => $jobId,
                    'duration_ms' => $finalMeta['duration_ms'] ?? 0,
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'status'     => 'error',
                    'error_code' => $finalMeta['error_code'] ?? 'worker_error',
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // 2. Status Endpoint: action=transcribe_status (GET)
    // -------------------------------------------------------------------------

    public function status(): void
    {
        // Read-only session: release lock immediately
        session_write_close();

        $userId = (int) ($_SESSION['authUserID'] ?? 0);
        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode(['error' => 'unauthorized']);
            return;
        }

        $jobId     = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['job_id'] ?? ''));
        $pid       = (int) ($_GET['pid'] ?? 0);
        $encounter = (int) ($_GET['encounter'] ?? 0);

        if ($jobId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'missing_job_id']);
            return;
        }

        $jobDir  = $this->getSecureJobDir();
        $jobMeta = $this->readJobMeta($jobDir, $jobId);

        if (empty($jobMeta) || time() > ($jobMeta['expires_at'] ?? 0)) {
            http_response_code(404);
            echo json_encode(['error' => 'job_not_found']);
            return;
        }

        // Security check: Job must belong to the authenticated user
        if ((int) $jobMeta['user_id'] !== $userId) {
            http_response_code(403);
            echo json_encode(['error' => 'access_denied']);
            return;
        }

        // In clinical mode, verify binding to patient and encounter
        if (empty($jobMeta['is_test'])) {
            if ((int) $jobMeta['patient_id'] !== $pid || (int) $jobMeta['encounter_id'] !== $encounter) {
                http_response_code(403);
                echo json_encode(['error' => 'clinical_context_mismatch']);
                return;
            }
        }

        $status = $jobMeta['status'] ?? 'processing';

        if ($status === 'processing') {
            // Check for dead worker: if elapsed time exceeds timeout and lock is released
            $maxAudioSec   = (int) $this->settings->get('whisper_max_audio_sec', 180);
            $workerTimeout = max(30, (int) round($maxAudioSec * 1.5 + 30)) + 45;
            if (time() - ($jobMeta['created_at'] ?? time()) > $workerTimeout) {
                $jobMeta['status']     = 'error';
                $jobMeta['error_code'] = 'worker_timeout';
                $this->saveJobMeta($jobDir, $jobId, $jobMeta);
                http_response_code(200);
                echo json_encode([
                    'status'     => 'error',
                    'error_code' => 'worker_timeout',
                ]);
                return;
            }

            http_response_code(200);
            echo json_encode(['status' => 'processing']);
            return;
        }

        if ($status === 'completed') {
            // Retrieve transcript from secure 0600 file outside web root
            $transcriptFile = $jobDir . DIRECTORY_SEPARATOR . $jobId . '.txt';
            $text = '';
            if (file_exists($transcriptFile)) {
                $text = (string) file_get_contents($transcriptFile);
            }

            // Ephemeral: DELETE transcript file and job record on first successful retrieval
            @unlink($transcriptFile);
            @unlink($jobDir . DIRECTORY_SEPARATOR . $jobId . '.json');

            http_response_code(200);
            echo json_encode([
                'status'      => 'completed',
                'text'        => $text,
                'duration_ms' => $jobMeta['duration_ms'] ?? 0,
            ]);
            return;
        }

        // Status is error: clean up job record and return error code
        @unlink($jobDir . DIRECTORY_SEPARATOR . $jobId . '.json');
        http_response_code(200);
        echo json_encode([
            'status'     => 'error',
            'error_code' => $jobMeta['error_code'] ?? 'transcription_failed',
        ]);
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * Executes the Whisper transcription in the worker process.
     * Guaranteed to release lock and clean temporary audio in a finally block.
     */
    private function runTranscriptionWorker(
        string $jobDir,
        string $jobId,
        string $tempAudioPath,
        string $whisperUrl,
        int $inferenceTimeout,
        int $workerTimeout,
        $lockFp,
        string $lockFile,
        int $userId,
        int $pid,
        int $encounter,
        bool $isTest
    ): void {
        @set_time_limit($workerTimeout);
        @ignore_user_abort(true);

        try {
            $client = new TranscriptionClient($whisperUrl, $inferenceTimeout);
            $lang   = (string) $this->settings->get('output_language', 'es');

            $result = $client->transcribe($tempAudioPath, $lang);

            $jobMeta = $this->readJobMeta($jobDir, $jobId);
            if (!empty($jobMeta)) {
                if ($result['ok']) {
                    // Save transcript in a file with 0600 permissions outside web root (never in plain DB)
                    $transcriptFile = $jobDir . DIRECTORY_SEPARATOR . $jobId . '.txt';
                    file_put_contents($transcriptFile, $result['text'], LOCK_EX);
                    @chmod($transcriptFile, 0600);

                    $jobMeta['status']      = 'completed';
                    $jobMeta['duration_ms'] = $result['duration_ms'];
                    $this->saveJobMeta($jobDir, $jobId, $jobMeta);

                    // Metadata-only audit logging
                    $provider = $isTest ? 'whisper (test)' : 'whisper';
                    $this->audit->log($userId, $pid, $encounter, 'transcribe', $provider, 'whisper', 'ok', '', $result['duration_ms']);
                } else {
                    $jobMeta['status']      = 'error';
                    $jobMeta['error_code']  = $result['error_code'];
                    $jobMeta['duration_ms'] = $result['duration_ms'];
                    $this->saveJobMeta($jobDir, $jobId, $jobMeta);

                    // Metadata-only audit logging (error message never contains clinical content)
                    $provider = $isTest ? 'whisper (test)' : 'whisper';
                    $this->audit->log($userId, $pid, $encounter, 'transcribe', $provider, 'whisper', 'error', $result['error_code'], $result['duration_ms']);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('[AiAssistant] worker exception: ' . $e->getMessage());
            $jobMeta = $this->readJobMeta($jobDir, $jobId);
            if (!empty($jobMeta)) {
                $jobMeta['status']     = 'error';
                $jobMeta['error_code'] = 'worker_exception';
                $this->saveJobMeta($jobDir, $jobId, $jobMeta);
            }
        } finally {
            // Guaranteed cleanup of temp audio file
            if (file_exists($tempAudioPath)) {
                @unlink($tempAudioPath);
            }

            // Guaranteed release of exclusive lock
            if (is_resource($lockFp)) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
            if (file_exists($lockFile)) {
                @unlink($lockFile);
            }
        }
    }

    /**
     * Inspects actual file content using finfo and magic bytes.
     * Accepts: audio/webm, audio/ogg, audio/wav, audio/mp4, audio/x-m4a, audio/mpeg.
     */
    private function validateAudioContent(string $filePath): bool
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = strtolower((string) $finfo->file($filePath));

        $allowedMimes = [
            'audio/webm', 'video/webm',
            'audio/ogg', 'video/ogg', 'application/ogg',
            'audio/wav', 'audio/x-wav', 'audio/wave',
            'audio/mp4', 'audio/x-m4a', 'video/mp4',
            'audio/mpeg', 'audio/mp3',
        ];

        if (in_array($mime, $allowedMimes, true)) {
            return true;
        }

        // Header magic bytes fallback
        $handle = @fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }
        $header = (string) fread($handle, 16);
        fclose($handle);

        if (str_starts_with($header, "\x1A\x45\xDF\xA3")) {
            return true; // WebM / EBML
        }
        if (str_starts_with($header, 'OggS')) {
            return true; // Ogg
        }
        if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WAVE') {
            return true; // WAV
        }
        if (substr($header, 4, 4) === 'ftyp') {
            return true; // MP4 / M4A
        }
        if (str_starts_with($header, 'ID3') || str_starts_with($header, "\xFF\xFB") || str_starts_with($header, "\xFF\xF3")) {
            return true; // MP3
        }

        return false;
    }

    /**
     * Validates that the patient and encounter exist in OpenEMR.
     */
    private function validateClinicalContext(int $pid, int $encounter): bool
    {
        try {
            $patient = QueryUtils::fetchRecords(
                'SELECT `pid` FROM `patient_data` WHERE `pid` = ? LIMIT 1',
                [$pid]
            );
            if (empty($patient)) {
                return false;
            }

            $enc = QueryUtils::fetchRecords(
                'SELECT `encounter` FROM `form_encounter` WHERE `pid` = ? AND `encounter` = ? LIMIT 1',
                [$pid, $encounter]
            );
            return !empty($enc);
        } catch (\Throwable $e) {
            $this->logger->error('[AiAssistant] clinical context check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Returns the host-shared secure job directory outside web root with 0700 permissions.
     */
    private function getSecureJobDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oe_ai_jobs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    /**
     * Cleans up orphan temporary files older than 15 minutes.
     */
    private function cleanOrphanFiles(string $dir): void
    {
        $cutoff = time() - 900;
        $files  = @glob($dir . DIRECTORY_SEPARATOR . '*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    @unlink($file);
                }
            }
        }
    }

    private function saveJobMeta(string $jobDir, string $jobId, array $data): void
    {
        $file = $jobDir . DIRECTORY_SEPARATOR . $jobId . '.json';
        file_put_contents($file, json_encode($data), LOCK_EX);
        @chmod($file, 0600);
    }

    private function readJobMeta(string $jobDir, string $jobId): array
    {
        $file = $jobDir . DIRECTORY_SEPARATOR . $jobId . '.json';
        if (!file_exists($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        $arr = json_decode((string) $raw, true);
        return is_array($arr) ? $arr : [];
    }
}
