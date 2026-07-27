<?php

namespace Tests\Feature;

use App\Models\CertificadoFiscal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CertificadosFiscalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['negocio.facturacion_electronica_activo' => true]);
    }

    /**
     * Genera un certificado autofirmado + su clave privada (PEM), ambos como
     * UploadedFile, para probar la carga sin depender de archivos externos.
     *
     * @return array{0: UploadedFile, 1: UploadedFile, 2: string}
     */
    private function certificadoDePrueba(int $diasVigencia = 730): array
    {
        $configargs = ['config' => 'C:\\xampp\\php\\extras\\openssl\\openssl.cnf'];

        $key = openssl_pkey_new($configargs + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'Contagram Test'], $key, $configargs);
        $x509 = openssl_csr_sign($csr, null, $key, $diasVigencia, $configargs);

        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($key, $keyPem, null, $configargs);

        $certPath = tempnam(sys_get_temp_dir(), 'cert').'.crt';
        $keyPath = tempnam(sys_get_temp_dir(), 'key').'.key';
        file_put_contents($certPath, $certPem);
        file_put_contents($keyPath, $keyPem);

        return [
            new UploadedFile($certPath, 'homologacion.crt', 'application/x-x509-ca-cert', null, true),
            new UploadedFile($keyPath, 'homologacion.key', 'application/octet-stream', null, true),
            $certPem,
        ];
    }

    public function test_carga_valida_guarda_paths_y_encripta_la_clave(): void
    {
        [$certificado, $clave] = $this->certificadoDePrueba();

        $response = $this->post(route('certificados.store'), [
            'ambiente' => 'testing',
            'certificado' => $certificado,
            'clave_privada' => $clave,
        ]);

        $response->assertStatus(201)->assertJson(['ok' => true]);

        $certificadoFiscal = CertificadoFiscal::first();
        $this->assertNotNull($certificadoFiscal->certificado_path);
        $this->assertNotNull($certificadoFiscal->clave_privada_path);

        // La clave persistida en disco está encriptada, no es el PEM en texto plano.
        $claveEnDisco = \Illuminate\Support\Facades\Storage::disk('arca')->get($certificadoFiscal->clave_privada_path);
        $this->assertStringNotContainsString('PRIVATE KEY', $claveEnDisco);
    }

    public function test_la_clave_privada_nunca_aparece_en_la_respuesta_ni_en_data(): void
    {
        [$certificado, $clave] = $this->certificadoDePrueba();

        $store = $this->post(route('certificados.store'), [
            'ambiente' => 'testing',
            'certificado' => $certificado,
            'clave_privada' => $clave,
        ]);
        $store->assertStatus(201)->assertJsonMissing(['clave_privada_path']);
        $this->assertArrayNotHasKey('clave_privada_path', $store->json('data') ?? []);

        $data = $this->getJson(route('certificados.data'));
        $data->assertOk();
        $body = $data->getContent();
        $this->assertStringNotContainsString('clave_privada_path', $body);
    }

    public function test_al_activar_uno_se_desactivan_los_demas_del_mismo_ambiente(): void
    {
        [$cert1, $key1] = $this->certificadoDePrueba();
        [$cert2, $key2] = $this->certificadoDePrueba();

        $r1 = $this->post(route('certificados.store'), [
            'ambiente' => 'testing', 'activo' => 1, 'certificado' => $cert1, 'clave_privada' => $key1,
        ]);
        $id1 = $r1->json('data.id');

        $r2 = $this->post(route('certificados.store'), [
            'ambiente' => 'testing', 'certificado' => $cert2, 'clave_privada' => $key2,
        ]);
        $id2 = $r2->json('data.id');

        $this->assertDatabaseHas('certificados_fiscales', ['id' => $id1, 'activo' => true]);

        $this->postJson(route('certificados.activar', $id2))->assertOk();

        $this->assertDatabaseHas('certificados_fiscales', ['id' => $id2, 'activo' => true]);
        $this->assertDatabaseHas('certificados_fiscales', ['id' => $id1, 'activo' => false]);
    }

    public function test_rechaza_certificado_vencido(): void
    {
        // openssl_csr_sign con 0 días puede generar un certificado ya vencido al validarse "ahora".
        [$certificado, $clave] = $this->certificadoDePrueba(-1);

        $response = $this->post(route('certificados.store'), [
            'ambiente' => 'testing', 'certificado' => $certificado, 'clave_privada' => $clave,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('certificado', $response->json('errors'));
    }

    public function test_dias_para_vencer_correcto(): void
    {
        $certificado = CertificadoFiscal::create([
            'ambiente' => 'testing', 'certificado_path' => 'x', 'clave_privada_path' => 'y',
            'fecha_emision' => now()->subYear(), 'fecha_vencimiento' => now()->addDays(15), 'activo' => true,
        ]);
        $this->assertSame(15, $certificado->dias_para_vencer);

        $vencido = CertificadoFiscal::create([
            'ambiente' => 'testing', 'certificado_path' => 'x2', 'clave_privada_path' => 'y2',
            'fecha_emision' => now()->subYears(2), 'fecha_vencimiento' => now()->subDays(3), 'activo' => false,
        ]);
        $this->assertSame(-3, $vencido->dias_para_vencer);
    }

    public function test_rechaza_si_la_clave_no_corresponde_al_certificado(): void
    {
        [$certificado] = $this->certificadoDePrueba();
        [, $otraClave] = $this->certificadoDePrueba();

        $response = $this->post(route('certificados.store'), [
            'ambiente' => 'testing', 'certificado' => $certificado, 'clave_privada' => $otraClave,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('clave_privada', $response->json('errors'));
    }
}
