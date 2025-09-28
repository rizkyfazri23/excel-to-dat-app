<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class ActivityLog
 * 
 * @property int $id
 * @property string|null $user
 * @property string $action
 * @property string|null $details
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class ActivityLog extends Model
{
	protected $table = 'activity_logs';

	protected $fillable = [
		'user',
		'action',
		'details'
	];
}
