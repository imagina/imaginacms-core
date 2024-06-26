<?php

if (! function_exists('on_route')) {
    function on_route($route)
    {
        return Route::current() ? Route::is($route) : false;
    }
}

if (! function_exists('locale')) {
    function locale($locale = null)
    {
        if (is_null($locale)) {
            return app()->getLocale();
        }

        app()->setLocale($locale);

        return app()->getLocale();
    }
}

if (! function_exists('is_module_enabled')) {
    function is_module_enabled($module)
    {
        $activatorClass = get_class(app(config('modules.activators.file.class')));
        if ($activatorClass == "Modules\Isite\Activators\ModuleActivator") {
            $activator = app($activatorClass);

            return array_key_exists($module, array_intersect_key($activator->modulesStatuses, app('modules')->allEnabled()));
        }

        return array_key_exists($module, app('modules')->allEnabled());
    }
}

if (! function_exists('is_core_module')) {
    function is_core_module($module)
    {
        return in_array(strtolower($module), app('asgard.ModulesList'));
    }
}

if (! function_exists('is_core_theme')) {
    function is_core_theme(string $theme)
    {
        return in_array($theme, ['AdminLTE', 'Flatly'], false);
    }
}

if (! function_exists('asgard_i18n_editor')) {
    function asgard_i18n_editor($fieldName, $labelName, $content, $lang)
    {
        return view('core::components.i18n.textarea-wrapper', compact('fieldName', 'labelName', 'content', 'lang'));
    }
}

if (! function_exists('asgard_editor')) {
    function asgard_editor($fieldName, $labelName, $content)
    {
        return view('core::components.textarea-wrapper', compact('fieldName', 'labelName', 'content'));
    }
}

if (! function_exists('snakeToCamel')) {
    function snakeToCamel($input)
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $input))));
    }
}

if (! function_exists('camelToSnake')) {
    function camelToSnake($input)
    {
        $pattern = '!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!';
        preg_match_all($pattern, $input, $matches);
        $ret = $matches[0];
        foreach ($ret as &$match) {
            $match = $match == strtoupper($match) ?
              strtolower($match) :
              lcfirst($match);
        }

        return implode('_', $ret);
    }
}
