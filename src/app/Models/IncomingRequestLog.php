<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class IncomingRequestLog extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'incoming_requests';

    protected $guarded = [];
}
