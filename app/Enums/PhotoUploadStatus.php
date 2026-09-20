<?php

namespace App\Enums;

enum PhotoUploadStatus: string
{
    case Preparing = 'preparing';
    case Uploaded = 'uploaded';
    case Failed = 'failed';
}
