<?php

namespace Jankx\Extensions;

use Jankx\Extensions\AbstractExtension;

/**
 * Formarity Extension
 *
 * Wraps the Formello plugin code and runs it as a Jankx extension.
 * Since extensions live inside the theme (not wp-content/plugins),
 * we cannot rely on plugin_dir_path() / plugin_dir_url() which use the
 * global plugin file path.  All path/URL helpers are derived from
 * $this->get_extension_path() / $this->get_extension_url() instead.
 */
class FormarityExtension extends AbstractExtension
{
    /**
     * Absolute path to the root of this extension directory.
     * Set by ThemeExtensionManager::executeExtensionLoad() before init() runs.
     * @var string
     */
    protected $extPath;

    /**
     * Public URL to the root of this extension directory.
     * @var string
     */
    protected $extUrl;

    // -------------------------------------------------------------------------
    // AbstractExtension interface
    // -------------------------------------------------------------------------

    public function init(): void
    {
        // NOTE: extension_path and extension_url are NOT yet set by the
        // ThemeExtensionManager when __construct/__init runs.
        // They are set immediately after instantiation, before register_hooks().
        // Therefore, we initialise extPath/extUrl lazily in register_hooks().
    }

    /**
     * Called by ThemeExtensionManager after activate() → this is our "run" moment.
     * At this point we are inside after_setup_theme (priority 15), which means
     * all subsequent WP actions (init, admin_menu, rest_api_init …) haven't
     * fired yet — perfect timing for registering hooks.
     */
    public function register_hooks(): void
    {
        // Lazy-init: by now ThemeExtensionManager has called set_extension_path()
        // and set_extension_url(), so these are safe to read.
        $this->extPath = $this->get_extension_path();
        $this->extUrl  = $this->get_extension_url();

        // ------------------------------------------------------------------
        // 1. Ensure Composer autoloader for Formello classes is available.
        // ------------------------------------------------------------------
        $autoload = $this->extPath . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        // ------------------------------------------------------------------
        // 2. Utils helper files must be included AFTER WP 'init' so that
        //    translation functions (__(), _e()) are available. Composer no
        //    longer auto-includes them via "files" autoload for this reason.
        //    We load them on 'init' priority 0 (before everything else).
        // ------------------------------------------------------------------
        $extPath = $this->extPath;
        add_action('init', function () use ($extPath) {
            $utilsDir = $extPath . '/includes/Utils/';
            foreach (['functions.php', 'templates.php', 'register-cpt.php', 'register-settings.php'] as $file) {
                $fullPath = $utilsDir . $file;
                if (file_exists($fullPath)) {
                    require_once $fullPath;
                }
            }
        }, 0); // priority 0 = before other init hooks

        // ------------------------------------------------------------------
        // 4. i18n
        // ------------------------------------------------------------------
        add_action('init', function () {
            load_textdomain(
                'formello',
                $this->extPath . '/languages/' . get_locale() . '.mo'
            );
        }, 1);

        // ------------------------------------------------------------------
        // 5. Blocks (CPT blocks, shortcode) — created inside init so Utils
        //    functions are already loaded (priority 0 above).
        // ------------------------------------------------------------------
        add_action('init', function () {
            $blocks = new \Formello\Blocks('formello', $this->get_version(), $this->extPath . '/formarity.php');
            $blocks->register_blocks();
            $blocks->register_block_pattern_category();
            add_shortcode('formello', [$blocks, 'do_reusable_block']);
        }, 5);
        add_filter('block_categories_all', function ($categories) {
            // block_categories_all fires after init so Blocks can be constructed here
            $blocks = new \Formello\Blocks('formello', $this->get_version(), $this->extPath . '/formarity.php');
            return $blocks->register_block_category($categories);
        });

        // ------------------------------------------------------------------
        // 5. Frontend (AJAX form submit)
        // ------------------------------------------------------------------
        $frontend = new \Formello\Frontend('formello', $this->get_version());
        add_action('wp_ajax_formello',        [$frontend, 'listen_for_submit']);
        add_action('wp_ajax_nopriv_formello', [$frontend, 'listen_for_submit']);

        // ------------------------------------------------------------------
        // 6. REST API routes
        // ------------------------------------------------------------------
        add_action('rest_api_init', function () {
            $ep = $this->extPath . '/formarity.php';
            (new \Formello\Rest\Submissions($ep))->register_routes();
            (new \Formello\Rest\Columns($ep))->register_routes();
            (new \Formello\Rest\License($ep))->register_routes();
            (new \Formello\Rest\Importer($ep))->register_routes();
            (new \Formello\Rest\Settings($ep))->register_routes();
        });

        // ------------------------------------------------------------------
        // 7. Admin area
        // ------------------------------------------------------------------
        add_action('admin_menu', function () {
            $admin = new \Formello\Admin('formello', $this->get_version(), $this->extPath . '/formarity.php');
            $admin->admin_menu();
        });
        add_action('enqueue_block_editor_assets', function () {
            $admin = new \Formello\Admin('formello', $this->get_version(), $this->extPath . '/formarity.php');
            $admin->enqueue_editor_scripts();
        });
        add_action('admin_bar_menu', function ($adminBar) {
            $admin = new \Formello\Admin('formello', $this->get_version(), $this->extPath . '/formarity.php');
            $admin->admin_bar_item($adminBar);
        }, 1000);

        // ------------------------------------------------------------------
        // 8. Cron tasks — deferred to 'init' so Cron constructor can safely
        //    call WP schedule functions.
        // ------------------------------------------------------------------
        add_action('init', function () {
            $cron = new \Formello\Cron('formello', $this->get_version());
            $cron->cron();
            add_action('formello_retrieve_news', [$cron, 'get_news']);
            add_action('formello_delete_logs',   [$cron, 'delete_logs']);
            add_action('formello_delete_tmp',    [$cron, 'delete_tmp']);
        }, 5);

        // ------------------------------------------------------------------
        // 9. Email action
        // ------------------------------------------------------------------
        $email = new \Formello\Actions\Email();
        $email->hook();

        // ------------------------------------------------------------------
        // 10. Fix asset URLs: plugin_dir_url() maps to the PLUGIN folder,
        //     but our entry point lives inside the theme extensions dir.
        //     We intercept and return the correct theme-relative URL.
        // ------------------------------------------------------------------
        add_filter('plugins_url', [$this, 'fixPluginUrl'], 10, 3);

        // ------------------------------------------------------------------
        // 11. DB activation is now handled by the install() lifecycle method
        //     in ThemeExtensionManager.
        // ------------------------------------------------------------------
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Intercept plugins_url() calls originating from inside this extension and
     * return the correct URL relative to the extension directory instead.
     *
     * @param string $url    The resolved URL.
     * @param string $path   Relative path appended to the URL.
     * @param string $plugin Absolute path to the file that triggered the call.
     * @return string
     */
    public function fixPluginUrl(string $url, string $path, string $plugin): string
    {
        // Only rewrite URLs for files that live inside our extension directory.
        if (strpos(wp_normalize_path($plugin), wp_normalize_path($this->extPath)) === 0) {
            return rtrim($this->extUrl, '/') . '/' . ltrim($path, '/');
        }
        return $url;
    }

    /**
     * install() is called by ThemeExtensionManager exactly once per version.
     * This is the right place for database table creation and other one-time setup.
     *
     * @return bool
     */
    public function install(): bool
    {
        // Ensure autoloader is ready (may not be if called early).
        $autoload = $this->get_extension_path() . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        // Create DB tables, upload dirs, etc.
        \Formello\Activator::activate();

        do_action('jankx/extension/installed', 'formarity');
        return true;
    }
}
