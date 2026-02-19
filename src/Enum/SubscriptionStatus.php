<?php

namespace App\Enum;

enum SubscriptionStatus: string
{
    case PENDING = 'pending';
    case PROVISIONING = 'provisioning';
    case CONFIGURING = 'configuring';
    case DEPLOYING = 'deploying';
    case READY = 'ready';
    case ERROR = 'error';
}
