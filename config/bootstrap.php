<?php
/**
 * This plugin was created for working with twitter bootstrap in a development environment.
 *
 * I got tired of duplicating my changes every time I wanted to change bootstrap versions or upgrade.
 * This plugin allows you to configure which bootstrap modules are enabled, replace default glyphicons
 * with font-awesome, and add your custom less files so they have access to bootstrap's variables and
 * mixins.
 *
 * Usage:
 *
 * {{{
 * Libraries::add('li3_fontstrap', array(
 *     'disabled' => array('progress-bars', 'accordion', 'carousel', 'hero-unit'), // disable unused componenets.
 *     'custom' => array('style'), // Add custom .less files from your `app/webroot/less` folder.
 *     'font-awesome' => false, // Enabled by default.
 * ));
 * }}}
 *
 * In your layout, just include the `bootstrap.css` as usual...
 *
 * {{{
 * echo $this->html->style('bootstrap');
 * }}}
 *
 * @todo: Add minfiy support.
 * @todo: Add bootstrap and font-awesome documentation support.
 * @todo: Add support for bootstrap javascript files.
 * @todo: Clean up filters. Move logic to external classes.
 */

use lithium\core\Libraries;
use lithium\action\Dispatcher;
use lithium\action\Response;

$options = Libraries::get('li3_fontstrap') + array(
    'bootstrap_path' => LITHIUM_LIBRARY_PATH . '/_source/bootstrap',
    'font_path'      => LITHIUM_LIBRARY_PATH . '/_source/Font-Awesome',
    'lessc_path'     => LITHIUM_LIBRARY_PATH . '/_source/lessphp',
    'import_path'    => LITHIUM_APP_PATH . '/webroot/less',
    'cache_path'     => LITHIUM_APP_PATH . '/resources/tmp/cache/css',
    'bootstrap_name' => 'bootstrap.css',
    'minify'         => false, // Minify compiled css files. Not yet used.
    'font-awesome'   => true,  // Replaces the bootstrap glyphicons with font-awesome.
    'disabled'       => array(),
    'custom'         => array(),
);

Dispatcher::applyFilter('run', function($self, $params, $chain) use ($options) {
    $request = $params['request'];

    if ($options['font-awesome']) {
        if (preg_match('/((?:fontawesome)(?:-webfont)?\.(?:eot|svg|ttf|woff|otf))$/i', $request->url, $matches)) {
            return new Response(array(
                'body' => file_get_contents($options['font_path'] . '/font/' . $matches[1]),
                'headers' => array('Content-type' => 'font/opentype'),
            ));
        }
    } else {
        if (preg_match('/(glyphicons-halflings(?:-white)?\.png)/', $request->url, $matches)) {
            return new Response(array(
                'body' => file_get_contents($options['bootstrap_path'] . '/img/' . $matches[1]),
                'headers' => array('Content-type' => 'image/png'),
            ));
        }
    }

    return $chain->next($self, $params, $chain);
});

Dispatcher::applyFilter('run', function($self, $params, $chain) use ($options) {
    $request = $params['request'];

	if (!strstr($request->url, $options['bootstrap_name'])) {
		return $chain->next($self, $params, $chain);
	}

    if (!is_dir($options['cache_path'])) mkdir($options['cache_path']);

    $bootstrap_hash = sha1(serialize($options['disabled']) . '-' . $options['font-awesome']);
    $custom_hash = sha1(serialize(array_filter(array_map(function($v) use ($options) {
        $file = sprintf('%s/%s.less', $options['import_path'], $v);
        if (file_exists($file)) return filemtime($file);
    }, $options['custom']))));

    $cache_file = sprintf('%s/bs_%s.%s.css', $options['cache_path'], $bootstrap_hash, $custom_hash);

    if (file_exists($cache_file)) {
        return new Response(array(
            'body' => file_get_contents($cache_file),
            'headers' => array('Content-type' => 'text/css'),
        ));
    }

    foreach (glob($options['cache_path'] . '/bs_*.css') as $file) {
        unlink($file);
    }

	$bootstrap_path = $options['bootstrap_path'] . '/less';
    $font_path = $options['font_path'] . '/less';

	$less_file = str_replace('.css', '.less', "{$bootstrap_path}/bootstrap.less");

	if (!file_exists($less_file)) {
		return $chain->next($self, $params, $chain);
	}

    $less = file_get_contents($less_file);

    foreach ($options['disabled'] as $disabled) {
        $less = str_replace('@import "' . $disabled . '.less";', '', $less);
    }

    foreach ($options['custom'] as $custom) {
        $less .= sprintf("\n@import \"%s.less\";\n", $custom);
    }

    if ($options['font-awesome']) {
        $less = str_replace('glyphicons.less', 'font-awesome.less', $less);
    }

    try {
        require $options['lessc_path'] . '/lessc.inc.php';

        $lessc = new lessc();
        $lessc->setImportDir(array($bootstrap_path, $font_path, $options['import_path']));

        $less = $lessc->compile($less);
        file_put_contents($cache_file, $less);
    } catch (Exception $e) {
        $less = "lessphp fatal error: " . $e->getMessage();
    }

	return new Response(array('body' => $less, 'headers' => array('Content-type' => 'text/css')));
});

