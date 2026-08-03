<?php

return [
    'ambiente' => env('ARCA_AMBIENTE', 'homologacion'),

    'wsdl' => [
        'wsaa' => [
            'homologacion' => env('ARCA_WSDL_WSAA', 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms?wsdl'),
            'produccion' => 'https://wsaa.afip.gov.ar/ws/services/LoginCms?wsdl',
        ],
        'wsfev1' => [
            'homologacion' => env('ARCA_WSDL_WSFEV1', 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL'),
            'produccion' => 'https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL',
        ],
        'ws_sr_padron_a13' => [
            'homologacion' => env('ARCA_WSDL_PADRON_A13', 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA13?wsdl'),
            'produccion' => 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13?wsdl',
        ],
        'wsaa_url' => [
            'homologacion' => 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms',
            'produccion' => 'https://wsaa.afip.gov.ar/ws/services/LoginCms',
        ],
    ],

    'dias_aviso_vencimiento_certificado' => 30,
];
