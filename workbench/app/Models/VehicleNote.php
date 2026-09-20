<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $identity_vehicle_id
 * @property string $note
 */
class VehicleNote extends Model
{
    protected $table = 'vehicle_notes';

    protected $guarded = [];
}
