<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class Asset extends Model {
 protected $guarded=[];
 protected function casts():array{return ['purchased'=>'boolean','purchase_date'=>'date:Y-m-d','report_date'=>'datetime'];}
 public function employee(){return $this->belongsTo(Employee::class);}
 public function deviceModel(){return $this->belongsTo(DeviceModel::class);}
 public function specifications(){return $this->hasOne(AssetAttribute::class);}
}
