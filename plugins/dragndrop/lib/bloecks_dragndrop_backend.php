<?php
/**
 * bloecks_dragndrop_backend class - basic backend functions for the plugin
 */
class bloecks_dragndrop_backend extends bloecks_backend
{
    /**
     * The name of the plugin
     * @var string
     */
    protected static $plugin_name = 'dragndrop';

    /**
     * Initialize the plugin in the backend
     */
    public static function init(rex_extension_point $ep)
    {
        // register action for display of the slice
        rex_extension::register('SLICE_SHOW_BLOECKS_BE', array('bloecks_dragndrop_backend', 'showSlice'));

        // call the addon init function - see blocks_backend:init() class
        parent::init($ep);
    }

    /**
     * Wraps a LI.rex-slice-draggable around both the block selector and the block itself
     * @param  rex_extension_point $ep [description]
     * @return string                  the slice content
     */
    public static function showSlice(rex_extension_point $ep)
    {
        if(rex::getUser()->hasPerm(static::getPermName()))
        {
            $subject = $ep->getSubject();

            // get setting 'display sort buttons' ?
            $sortbuttons = static::settings('hide_sort_buttons', true) ? ' has--no-sortbuttons' : '';

            // get setting 'display in compact mode' ?
            $compactmode = static::settings('display_compact', true) ? ' is--compact' : '';

            $subject = '<li class="rex-slice rex-slice-draggable' . $sortbuttons . $compactmode . '"><ul class="rex-slices is--undraggable">' . $subject . '</ul></li>';

            return $subject;
        }
    }

}
