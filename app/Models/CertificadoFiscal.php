<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class CertificadoFiscal extends Model
{
    use SoftDeletes;

    protected $table = 'certificados_fiscales';

    protected $fillable = [
        'cuit', 'ambiente', 'ruta_certificado', 'ruta_clave_privada',
        'fecha_emision', 'fecha_vencimiento', 'activo',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_vencimiento' => 'date',
        'activo' => 'boolean',
    ];

    public static function activo(): ?self
    {
        return static::query()->where('activo', true)->latest('id')->first();
    }

    /** Guarda el contenido del archivo cifrado en disco y devuelve la ruta relativa persistida. */
    public static function guardarArchivoCifrado(string $contenido, string $nombre): string
    {
        $ruta = 'arca/'.$nombre;
        Storage::disk('local')->put($ruta, Crypt::encryptString($contenido));

        return $ruta;
    }

    public function leerCertificado(): string
    {
        return Crypt::decryptString(Storage::disk('local')->get($this->ruta_certificado));
    }

    public function leerClavePrivada(): string
    {
        return Crypt::decryptString(Storage::disk('local')->get($this->ruta_clave_privada));
    }

    public function vencido(): bool
    {
        return $this->fecha_vencimiento !== null && $this->fecha_vencimiento->isPast();
    }

    public function proximoAVencer(int $diasAviso = 30): bool
    {
        return $this->fecha_vencimiento !== null
            && ! $this->vencido()
            && now()->diffInDays($this->fecha_vencimiento, false) <= $diasAviso;
    }
}
