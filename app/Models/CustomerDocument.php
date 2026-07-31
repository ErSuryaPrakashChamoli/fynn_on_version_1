<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $document_type
 * @property string|null $document_name
 * @property string $document_path
 * @property string|null $mime_type
 * @property int|null $file_size
 * @property int|null $uploaded_by
 * @property int $is_latest
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Customer $customer
 * @property-read \App\Models\User|null $uploader
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerDocument newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerDocument newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerDocument query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerDocument whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerDocument whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerDocument whereDocumentName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerDocument whereDocumentPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerDocument whereDocumentType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerDocument whereFileSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerDocument whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerDocument whereIsLatest($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerDocument whereMimeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerDocument whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CustomerDocument whereUploadedBy($value)
 * @mixin \Eloquent
 */
class CustomerDocument extends Model
{
    protected $fillable = [
        'customer_id',
        'document_type',
        'document_name',
        'document_path',
        'mime_type',
        'file_size',
        'uploaded_by',
        'is_latest',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    
}