<?php
namespace OpenEMR\Modules\AiAssistant\Settings;
enum ConsentStatus: string {
    case NotGiven    = '0';
    case Acknowledged = '1';
}
