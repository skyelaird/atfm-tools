<?php

declare(strict_types=1);

namespace Atfm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CTOTs loaded from external sources — file uploads, VATCAN event bookings,
 * or any other pre-computed source. Takes precedence over allocator output
 * when `priority` is lower (numerically). See ARCHITECTURE.md §4.8.
 */
final class ImportedCtot extends Model
{
    protected $table = 'imported_ctots';

    protected $fillable = [
        'source_file',
        'source_label',
        'source_uploaded_at',
        'callsign',
        'cid',
        'ctot',
        'most_penalizing_airspace',
        'priority',
        'valid_from',
        'valid_until',
        'active',
    ];

    protected $casts = [
        'cid'                => 'int',
        'ctot'               => 'datetime',
        'priority'           => 'int',
        'source_uploaded_at' => 'datetime',
        'valid_from'         => 'datetime',
        'valid_until'        => 'datetime',
        'active'             => 'bool',
    ];
}
