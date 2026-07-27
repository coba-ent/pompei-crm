<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="csrf-token" content="{{ csrf_token() }}">
<?php 

    $global_seo = !empty(config('dz.site_level.seo.meta')) ? config('dz.site_level.seo.meta') : array() ;
    $seo = !empty(config('dz.pagelevel.'.$CurrentPage.'.seo.meta')) ? config('dz.pagelevel.'.$CurrentPage.'.seo.meta') : $global_seo;

    if (!empty($seo)) {
        foreach ($seo as $tag) {
            if (isset($tag['name'])){
                echo '<meta name="'.$tag['name'].'" content="'.$tag['content'].'">',PHP_EOL;
            }
            elseif (isset($tag['property'])){
                echo '<meta name="'.$tag['property'].'" content="'.$tag['content'].'">',PHP_EOL;
            }
        }
    }
 ?>


