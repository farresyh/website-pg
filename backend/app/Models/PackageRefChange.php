<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** ADR-137 decision 21: a package repointed to a new supplier product. */
class PackageRefChange extends Model
{
    protected $fillable = ['package_id', 'old_ref', 'new_ref', 'admin_user_id'];
}
