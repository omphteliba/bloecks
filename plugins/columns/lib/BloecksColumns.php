<?php

abstract class BloecksColumns extends Bloecks
{
    protected static $column_name = 'bloecks_format';

    public static function getSliceFormat($slice, $clang = null, $type = null)
    {
        if($slice instanceof rex_article_slice)
        {
            $slice = $slice->getId();
        }
        else
        {
            $slice = (int) $slice;
        }

        if($clang === null || !in_array($clang, rex_clang::getAllIds()))
        {
            $clang = rex_clang::getCurrentId();
        }

        $formats = [];
        foreach(static::getConfigForSlice(rex_article_slice::getArticleSliceById($slice, $clang)) as $config => $options)
        {
            $formats[$config] = [
                'size' => $options['default'],
                'grid' => $options['grid']
            ];
        }

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTablePrefix().'article_slice');
        $sql->setWhere(array('id' => $slice, 'clang_id' => $clang));
        $sql->select();
        if($result = $sql->getArray())
        {
            $result = $result[0];

            if(!empty($result[static::$column_name]))
            {
                $result = explode(' ', $result[static::$column_name]);
                foreach($result as $r)
                {
                    $r = trim($r);

                    preg_match('/^([a-z]+)-(\d+)-(\d+)$/i', $r, $match);

                    // this is a grid format - let's parse!
                    if(!empty($match[1]) && isset($formats[$match[1]]))
                    {
                        $size = static::validateSize($slice, $match[1], [
                            'size' => (int) $match[2],
                            'grid' => (int) $match[3]
                        ]);

                        if($size)
                        {
                            $formats[$match[1]] = array_replace($formats[$match[1]], $size);
                        }

                        unset($size);
                    }
                    unset($match);
                }
                unset($r);
            }
        }
        unset($result, $sql);

        if(!empty($type))
        {
            if(isset($formats[$type]))
            {
                return $formats[$type];
            }
        }
        else
        {
            return $formats;
        }

        return null;
    }

    protected static function gdc($min, $gdc)
    {
        while ($min != 0)
        {
            $remain = $gdc % $min;
            $gdc = $min;
            $min = $remain;
        }
        return abs($gdc);
    }

    protected static function validateSize($slice, $type, $size)
    {
        $default = static::getDefaultSize($slice, $type);
        $grid = static::getGridSize($slice, $type);

        $slice_size = null;
        $slice_grid = $grid;

        $returns = 'single';

        if(empty($size))
        {
            return null;
        }

        if(!is_array($size))
        {
            $slice_size = (int) $size;
        }
        else
        {
            if(!isset($size['size']))
            {
                $returns = 'array';
                $size = array_values($size);
                $slice_size = (int) $size[0];
                if(isset($size[1]))
                {
                    $slice_grid = (int) $size[1];
                }
            }
            else {
                $returns = 'assoc';
                $slice_size = (int) $size['size'];

                if(isset($size['grid']))
                {
                    $slice_grid = (int) $size['grid'];
                }
            }
        }

        if($slice_size && $default && $grid)
        {
            $test = Min($slice_size, static::getMaxSize($slice, $type));
            $test = Max($slice_size, static::getMinSize($slice, $type));

            if($test != $slice_size)
            {
                $slice_size = $default;
                $slice_grid = $grid;
            }

            if($slice_grid != $grid)
            {
                $slice_size = round(($slice_size/$slice_grid) * $grid);
                $slice_grid = $grid;
            }

            unset($test);

            $gdc = static::gdc($slice_size, $slice_grid);

            $format = [
                'size' => $slice_size,
                'grid' => $slice_grid
            ];

            if($returns == 'assoc')
            {
                return $format;
            }
            else if($returns == 'array')
            {
                return array_values($format);
            }
            else
            {
                return $format['size'];
            }
        }
        unset($default, $grid, $returns, $slice_size, $slice_grid, $gdc);
        return null;
    }

    public static function getSliceCss($slice)
    {
        $css = [];

        foreach(static::getSliceFormat($slice) as $type => $size)
        {
            $css[] = 'bc--' . $type . '-' . $size['size'] . '-' . $size['grid'];
        }

        return $css;
    }


    public static function show($ep)
    {
        $subject = $ep->getSubject();

        $attributes = [];

        foreach(static::getSliceFormat($ep->getParam('slice_id')) as $type => $size)
        {
            $data = $size['size'] . ',' . $size['grid'];

            if(rex::isBackend())
            {
                $min = static::getMinSize($ep->getParam('slice_id'), $type);
                $max = static::getMaxSize($ep->getParam('slice_id'), $type);
                $data.=',' . $min . ',' . $max;


                switch($type)
                {
                    case 'columns' :
                        $attributes[] = 'style="width:' . number_format(($size['size'] / $size['grid']) * 100, 3, '.', '') . '%"';
                        break;
                }
            }

            $attributes[] = 'data-bloecks-' . $type . '="' . $data . '"';

        }
        unset($type, $size);

        if(rex::isBackend())
        {
            if(!preg_match('/<form/', $subject))
            {
                $subject = '<li class="rex-slice rex-slice-bloecks-item rex-slice-output"' . (!empty($attributes) ? ' '.join(' ', $attributes) : '') . '><ul>' . $subject . '</ul></li>';
            }
        }
        else
        {
            if($css = static::getSliceCss($ep->getParam('slice_id')))
            {
                $css = join(' ', $css);
                $find = '{{bloecks_columns_css}}';
                if(($p = strpos($subject, $find)) !== false)
                {
                    $subject = substr($subject, 0, $p) . $css . substr($subject, $p + strlen($find));
                }
                else
                {
                    $subject =  "\n" .
                                "echo '<div class=\"" . $css . "\">'; // bloecks_columns" .
                                "\n\n" .
                                $subject .
                                "\n" .
                                "echo '</div>'; // bloecks_columns wrapper" .
                                "\n";
                }
            }
        }

        return $subject;
    }

    public static function resizeAction()
    {
        $items = rex_request('resize', 'array', []);

        foreach($items as $slice_id => $item)
        {
            $slice = static::getSlice($slice_id);

            if($slice instanceof rex_article_slice)
            {
                static::resize($slice, [
                    'columns' => isset($item['x']) ? $item['x'] : null,
                    'rows' => isset($item['y']) ? $item['y'] : null
                ]);
            }
        }
    }

    protected static function setFormatOfSlice(rex_article_slice $slice, $format)
    {
        $format = array_replace(static::getSliceFormat($slice), $format);
        $upd = [];
        foreach($format as $k => $v)
        {
            $upd[] = $k . '-' . $v['size'] . '-' . $v['grid'];
        }

        $sql = rex_sql::factory();
        $sql->setDebug();
        if($sql->setQuery("UPDATE `" . rex::getTablePrefix() . "article_slice` SET `" . self::$column_name . "` = ? WHERE id = ?", array(join(' ', $upd), $slice->getId())))
        {
            BloecksBackend::regenerateArticleOfSlice($slice);

            rex_extension::registerPoint(new rex_extension_point('BLOECKS_SLICE_SIZE_UPDATED', '', [
              'slice' => $slice,
              'format' => $format
            ]));

            return true;
        }

        return false;
    }

    protected static function resize(rex_article_slice $slice, $sizes)
    {
        $formats = [];
        $default = static::getConfigForSlice($slice);

        foreach($default as $type => $options)
        {
            if(isset($sizes[$type]))
            {
                $size = (int) $sizes[$type];
                if(!empty($size) && !is_nan($size))
                {
                    if($size >= $options['min'] && $size <= $options['max'])
                    {
                        $formats[$type] = [
                            'size' => $size,
                            'grid' => $options['grid']
                        ];
                    }
                }
            }
        }
        return static::setFormatOfSlice($slice, $formats);
    }

    protected static function getConfigNumber(array $config = [], $what = 'grid', $default = 1)
    {
        if(!isset($config[$what]))
        {
            return 0;
        }

        $return = intval($config[$what]);
        $return = is_nan($return) ? (int) $default : $return; // set default value if config is invalid
        $return = is_nan($return) ? 1 : Max(1, $return); // make sure we have something > 0;

        switch($what)
        {
            case 'min' :
                $return = Min($return, static::getConfigNumber($config, 'max'));
                break;
            case 'max' :
                $return = Min($return, static::getConfigNumber($config, 'grid'));
                break;
            case 'default' :
                $return = Min($return, static::getConfigNumber($config, 'max'));
                $return = Max($return, static::getConfigNumber($config, 'min'));
                break;
        }

        return $return;
    }

    public static function getConfigForContentType(array $ids, $type = null)
    {
        $config = [];

        $default_rules = [];
        $slice_rules = [];

        $settings = static::getProperty('grids');
        foreach($settings as $setting)
        {
            $default_rules[$setting] = static::getConfig($setting);
        }
        unset($settings, $setting);

        if($advanced_config = static::getConfig('advanced'))
        {
            $advanced_config = explode("\n", $advanced_config);
            $advanced_config = array_filter($advanced_config);
            $advanced_config = array_unique($advanced_config);

            $slice_rules = [];

            foreach($advanced_config as $line)
            {
                $score = 0;
                foreach($ids as $k => $id)
                {
                    if(preg_match("/$k:$id($|\t|\n|,|;|\s)?/", $line))
                    {
                        $score++;
                    }
                }
                unset($k, $id);

                if($score > 0)
                {
                    $slice_configs = [];

                    preg_match_all("/([a-z]+)_([a-z]+):(\d+)/i", $line, $matches);
                    if(!empty($matches[1]))
                    {
                        foreach($matches[1] as $i => $config_name)
                        {
                            if(!isset($default_rules[$config_name]))
                            {
                                $default_rules[$config_name] = static::getConfig($config_name);
                            }

                            if(!empty($default_rules[$config_name]) && isset($default_rules[$config_name][$matches[2][$i]]))
                            {
                                $slice_configs[$config_name][$matches[2][$i]] = Max(1, (int) $matches[3][$i]);
                            }
                        }
                    }
                    $slice_configs = array_filter($slice_configs);

                    if(!empty($slice_configs))
                    {
                        $slice_rules[$score] = $slice_configs;
                    }
                    unset($slice_configs, $matches, $i);
                }
                unset($score);
            }
            unset($ids);

            ksort($slice_rules);

            foreach($slice_rules as $score => $rule)
            {
                foreach($rule as $what => $options)
                {
                    $default_rules[$what] = array_replace($default_rules[$what], $options);
                }
            }
            unset($slice_rules, $score, $rule, $what, $options);
        }
        unset($advanced_config);

        if(!empty($type))
        {
            if(isset($default_rules[$type]))
            {
                return $default_rules[$type];
            }
        }
        else
        {
            return $default_rules;
        }

        return null;
    }

    public static function getConfigForSlice(rex_article_slice $slice, $type = null)
    {
        $ids = [
            'module' => $slice->getModuleId(),
            'ctype' => $slice->getCtype(),
            'template' => $slice->getArticle()->getTemplateId(),
            'clang' => $slice->getClang(),
            'article' => $slice->getArticleId()
        ];

        return static::getConfigForContentType($ids, $type);
    }

    public static function getMaxSize($slice = null, $type = 'columns')
    {
        return static::getSize($slice, $type, 'max');
    }

    public static function getMinSize($slice = null, $type = 'columns')
    {
        return static::getSize($slice, $type, 'min');
    }

    public static function getDefaultSize($slice = null, $type = 'columns')
    {
        return static::getSize($slice, $type, 'default');
    }

    public static function getGridSize($slice = null, $type = 'columns')
    {
        return static::getSize($slice, $type, 'grid');
    }

    protected static function getSize($slice = null, $type, $what)
    {
        if(!($slice instanceof rex_article_slice))
        {
            $slice = rex_article_slice::getArticleSliceById((int) $slice);
        }

        if($slice instanceof rex_article_slice)
        {
            $config = static::getConfigForSlice($slice, $type);
        }

        if(empty($config))
        {
            $config = static::getConfig($type);
        }

        return static::getConfigNumber($config, $what);
    }
}

?>
