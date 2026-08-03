<?php

namespace App\Services\Arca\Excepciones;

/** Timeout o caída de red hacia WSAA/WSFEv1 (FR-010, FR-011). No se sabe si ARCA llegó a procesar la solicitud. */
class ArcaNoDisponibleException extends ArcaException {}
