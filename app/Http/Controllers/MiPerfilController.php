<?php

namespace App\Http\Controllers;

use App\Models\CondicionIva;
use App\Models\DatosEmpresa;
use App\Models\Rol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Configuración & Ajustes → Empresa (spec 043, ex "Mi Perfil"): datos fiscales del negocio emisor + gestión de usuarios. */
class MiPerfilController extends Controller
{
    public function index()
    {
        $CurrentPage = 'configuracion-mi-perfil';
        $datosEmpresa = DatosEmpresa::instancia();
        $condicionesIva = CondicionIva::orderBy('nombre')->get();
        $roles = Rol::orderBy('nombre')->get();

        return view('configuracion.mi-perfil.index', compact('CurrentPage', 'datosEmpresa', 'condicionesIva', 'roles'));
    }

    public function guardar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'razon_social' => ['nullable', 'string', 'max:255'],
            'cuit' => ['nullable', 'digits:11'],
            'domicilio_fiscal' => ['nullable', 'string', 'max:255'],
            'condicion_iva' => ['nullable', 'string', 'max:255'],
            'ingresos_brutos' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $datosEmpresa = DatosEmpresa::instancia();

        if ($request->hasFile('logo')) {
            $rutaLogo = $request->file('logo')->store('empresa', 'public');
            if ($datosEmpresa && $datosEmpresa->ruta_logo) {
                Storage::disk('public')->delete($datosEmpresa->ruta_logo);
            }
            $datos['ruta_logo'] = $rutaLogo;
        }

        unset($datos['logo']);

        $datosEmpresa = $datosEmpresa ?? new DatosEmpresa;
        $datosEmpresa->fill($datos);
        $datosEmpresa->save();

        return response()->json([
            'ok' => true,
            'mensaje' => 'Mi Perfil guardado con éxito.',
            'datos_empresa' => $datosEmpresa,
        ]);
    }

    public function actualizarPassword(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'password_actual' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $usuario = Auth::user();

        if (! Hash::check($datos['password_actual'], $usuario->password)) {
            throw ValidationException::withMessages([
                'password_actual' => ['La contraseña actual no es correcta.'],
            ]);
        }

        $usuario->forceFill(['password' => Hash::make($datos['password'])])->save();

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }
}
