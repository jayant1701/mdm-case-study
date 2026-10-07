<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class DeviceModel extends Model {
 protected $guarded=[];
 public function assets(){return $this->hasMany(Asset::class);}
}
