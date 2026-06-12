<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class OutgoingRequestLog extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'outgoing_requests';

    protected $guarded = [];
}
