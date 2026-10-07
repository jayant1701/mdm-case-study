<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class RestoreTask extends Model {
 protected $guarded=[];
 protected function casts():array{return ['deleted_at'=>'datetime'];}
}
