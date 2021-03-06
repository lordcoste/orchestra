<?php namespace Orchestra;

use \Bundle,
	\Exception,
	\IoC,
	FileSystemIterator as fIterator;

class Extension {

	/**
	 * List of extensions
	 *
	 * @var array
	 */
	protected static $extensions = array();

	/**
	 * Load an extension by running it's start-up script.
	 *
	 * If the extension has already been started, no action will be taken.
	 *
	 * @static
	 * @access public
	 * @param  string  $name
	 * @param  array   $config
	 * @return void
	 */
	public static function start($name, $config = array())
	{
		$default = array('handles' => null, 'auto' => false, 'web_upgrade' => false);
		$name    = $name ?: null;
		$config  = (array) $config;

		if ( ! is_string($name)) return;

		// Register extension and auto-start it
		Bundle::register($name, $config);
		Bundle::start($name);

		// by now, extension should already exist as an extension. We should
		// be able start orchestra.php starter file on each bundles.
		if (is_file($file = Bundle::path($name).'orchestra'.EXT))
		{
			include_once $file;
		}

		static::$extensions[$name] = array_merge($default, $config);
	}

	/**
	 * Check if extension is started
	 *
	 * @static
	 * @access public
	 * @param  string $name
	 * @return bool
	 */
	public static function started($name)
	{
		return (array_key_exists($name, static::$extensions));
	}

	/**
	 * Get an option for a given extension.
	 *
	 * @static
	 * @access public
	 * @param  string  $name
	 * @param  string  $option
	 * @param  mixed   $default
	 * @return mixed
	 */
	public static function option($name, $option, $default = null)
	{
		$extension = static::$extensions[$name];

		if (is_null($extension))
		{
			return value($default);
		}

		return array_get($extension, $option, $default);
	}

	/**
	 * Load extensions for Orchestra (from a list of folders)
	 *
	 * @static
	 * @access protected
	 * @param  array    $bundles
	 * @return array
	 */
	protected static function load($bundles = array())
	{
		$extensions = array();

		foreach ($bundles as $name => $path)
		{
			if (is_file($path.'orchestra.json'))
			{
				$extensions[$name] = json_decode(file_get_contents($path.'orchestra.json'));

				if (is_null($extensions[$name]))
				{
					// json_decode couldn't parse, throw an exception
					throw new Exception("Extension [{$name}]: cannot decode orchestra.json file");
				}
			}
		}

		return $extensions;
	}

	/**
	 * Detect all of the extensions for Orchestra
	 *
	 * @static
	 * @access public
	 * @return array
	 */
	public static function detect($bundles = array())
	{
		if (empty($bundles))
		{
			$bundles[DEFAULT_BUNDLE] = path('app');

			$items = new fIterator(path('bundle'), fIterator::SKIP_DOTS);

			foreach ($items as $item)
			{
				if ( ! $item->isDir()) continue;

				$bundles[$item->getFilename()] = rtrim($item->getRealPath(), DS).DS;
			}
		}

		$extensions = static::load($bundles);
		$cached     = array();

		// we should cache extension to be stored to Hybrid\Memory to avoid
		// over usage of database space
		foreach ($extensions as $name => $extension)
		{
			$ext_name    = isset($extension->name) ? $extension->name : null;
			$ext_version = isset($extension->version) ? $extension->version : '>0';
			$ext_config  = isset($extension->config) ? $extension->config : array();
			$ext_require = isset($extension->require) ? $extension->require : array();

			if (is_null($ext_name)) continue;

			$cached[$name] = array(
				'name'    => $ext_name,
				'version' => $ext_version,
				'config'  => (array) $ext_config,
				'require' => (array) $ext_require,
			);
		}

		Core::memory()->put('extensions.available', $cached);

		return $extensions;
	}

	/**
	 * Activate an extension
	 *
	 * @static
	 * @access public
	 * @param  string   $name
	 * @return void
	 */
	public static function activate($name)
	{
		$memory    = Core::memory();
		$available = (array) $memory->get('extensions.available', array());
		$active    = (array) $memory->get('extensions.active', array());

		if (isset($available[$name]))
		{
			$active[$name] = (array) $available[$name]['config'];

			$dependencies = static::not_activatable($name);

			if ( ! empty($dependencies))
			{
				throw new Extension\UnresolvedException($dependencies);
			}

			// we should also start the bundle
			static::start($name, $active[$name]);
			static::publish($name);
		}

		$memory->put('extensions.active', $active);
	}

	/**
	 * Determine whether the requested extension is active
	 *
	 * @static
	 * @access public
	 * @param  string   $name
	 * @return bool
	 */
	public static function activated($name)
	{
		$memory    = Core::memory();
		$active    = (array) $memory->get('extensions.active', array());

		return (in_array($name, $active));
	}

	/**
	 * Deactivate an extension
	 *
	 * @static
	 * @access public
	 * @param  string   $name
	 * @return void
	 */
	public static function deactivate($name)
	{
		$memory  = Core::memory();
		$current = (array) $memory->get('extensions.active', array());
		$active  = array();

		foreach ($current as $extension => $config)
		{
			if (is_numeric($extension))
			{
				$extension = $config;
				$config    = array();
			}

			if ($extension !== $name)
			{
				$active[$extension] = $config;
			}
		}

		$available    = $memory->get('extensions.available');
		$name         = $available[$name]['name'];
		$dependencies = array();

		// we should check that other extensions don't depend on it
		foreach ($active as $bundle => $extension)
		{
			if (in_array($name, array_keys($available[$bundle]['require'])))
			{
				$dependencies[] = $available[$bundle]['name'];
			}
		}

		if ( ! empty($dependencies)) throw new Extension\UnresolvedException($dependencies);

		$memory->put('extensions.active', $active);
	}

	/**
	 * Publish migration and asset for an extension
	 *
	 * @static
	 * @access public
	 * @param  string   $name
	 * @return void
	 */
	public static function publish($name)
	{
		IoC::resolve('task: orchestra.migrator', array('migrate', $name));
		IoC::resolve('task: orchestra.publisher', array($name));
	}

	/**
	 * Get all of the installed extensions for the application.
	 *
	 * @static
	 * @access public
	 * @return array
	 */
	public static function all()
	{
		return static::$extensions;
	}

	/**
	 * Get the identifier from an extension name
	 *
	 * @static
	 * @access protected
	 * @param  string   $name
	 * @return string
	 */
	protected static function identifier($name)
	{
		foreach (Core::memory()->get('extensions.available') as $bundle => $extension)
		{
			if ($extension['name'] === $name) return $bundle;
		}
	}

	/**
	 * Solve dependencies for an extension and
	 * return if an extension can't be activated.
	 *
	 * @static
	 * @access public
	 * @param  string   $name
	 * @return array
	 */
	public static function not_activatable($name)
	{
		return static::unresolved($name, true);
	}

	/**
	 * Solve dependencies for an extension and
	 * return the array of the unsolved dependencies
	 *
	 * @static
	 * @access public
	 * @param  string   $name
	 * @param  bool     $is_activatable
	 * @return array
	 */
	public static function unresolved($name, $is_activatable = false)
	{
		$unresolved = array();
		$available  = Core::memory()->get("extensions.available");
		$requires   = array_get($available, "{$name}.require", array());

		foreach ($requires as $reference => $version)
		{
			$is_bundle = false;

			// Whenever the version is marked as `bundle`, we can assume
			// this is a bundle.
			if ($version === 'bundle')
			{
				$is_bundle = true;
				$version   = '0';
			}

			list($op) = preg_split("/\d+/", $version, 2);
			$version  = str_replace($op, '', $version);

			// Check if the requirement is a bundle, we can ignore it if
			// bundle is already started.
			if ($is_bundle and Bundle::started($reference)) continue;

			// If require are using name instead of identifier, we need to get the identifier.
			if ( ! is_null($identifier = static::identifier($reference)))
			{
				$reference = $identifier;
			}

			// Now check for an extension, at the same time will also detect
			// if the dependencies is updated with the
			if (static::started($reference) and ! $is_bundle)
			{
				if ( ! version_compare($available[$reference]['version'], $version, $op))
				{
					$unresolved[] = array('name' => $reference, 'version' => $op.$version);
				}

				continue;
			}

			$op      = empty($op) ? '>=' : $op;
			$version = empty($version) ? '0' : $version;

			// If we need to check if such extension can be activated,
			// useful when we want to check if such extension is outdated.
			if ( !! $is_activatable)
			{
				$unresolved[] = array('name' => $reference, 'version' => $op.$version);
				continue;
			}

			// final check, verify the dependencies is available (registered), and
			// compare the version.
			if ( ! isset($available[$reference])
				or ! version_compare($available[$reference]['version'], $version, $op))
			{
				$op           = ($op == '=') ? 'v' : $op;
				$unresolved[] = array('name' => $reference, 'version' => $op.$version);
			}
		}

		return $unresolved;
	}
}