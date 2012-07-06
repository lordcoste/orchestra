<?php namespace Orchestra;

use \Config, \Exception, 
	Hybrid\Acl, Hybrid\Memory;

class Core
{
	/**
	 * Core initiated status
	 *
	 * @static
	 * @access  protected
	 * @var     boolean
	 */
	protected static $initiated = false;

	/**
	 * Cached instances for Orchestra
	 * 
	 * @static
	 * @access  protected
	 * @var     array
	 */
	protected static $cached = array();

	/**
	 * Start Orchestra\Core
	 *
	 * @static
	 * @access public
	 * @return void
	 * @throws Exception If memory instance is not available (database not set yet)
	 */
	public static function start()
	{
		// avoid current method from being called more than once.
		if (true === static::$initiated) return ;

		// Make Menu instance
		static::$cached['orchestra_menu'] = Widget::make('menu.orchestra');
		
		// Make Menu instance for frontend application
		static::$cached['app_menu'] = Widget::make('menu.application');

		// Make ACL instance
		static::$cached['acl'] = Acl::make('orchestra');

		// First, we need to ensure that Hybrid\Acl is compliance with 
		// our Eloquent Model, This would overwrite the default configuration
		Config::set('hybrid::auth.roles', function ($user_id, $roles)
		{
			$user = Model\User::with('roles')->find($user_id);

			foreach ($user->roles as $role)
			{
				array_push($roles, $role->name);
			}

			return $roles;
		});

		try 
		{
			// Initiate Memory class
			static::$cached['memory'] = Memory::make('fluent.orchestra_options');

			$users = Model\User::all();

			if (empty($users))
			{
				throw new Exception('User table is empty');
			}

			// In event where we reach this point, we can consider no exception has
			// occur, we should be able to compile acl and menu configuration
			static::$cached['acl']->attach(static::$cached['memory']);
			
			// Add basic menu.
			static::$cached['orchestra_menu']->add('home')->title('Home')->link('orchestra');

			// Add menu when user can manage users
			if (static::$cached['acl']->can('manage-users'))
			{
				static::$cached['orchestra_menu']->add('users')->title('Users')->link('orchestra/users');
				static::$cached['orchestra_menu']->add('add-users', 'childof:users')->title('Add Users')->link('orchestra/users/add');
			}

			// Add menu when user can manage orchestra
			if (static::$cached['acl']->can('manage-orchestra'))
			{
				static::$cached['orchestra_menu']->add('themes', 'after:home')->title('Themes')->link('orchestra/themes');
				static::$cached['orchestra_menu']->add('menus', 'childof:themes')->title('Menus')->link('orchestra/menus');
				static::$cached['orchestra_menu']->add('widgets', 'childof:themes')->title('Widgets')->link('orchestra/widgets');
				static::$cached['orchestra_menu']->add('settings')->title('Settings')->link('orchestra/settings');
			}

			// In any event where Memory failed to load, we should set Installation status 
			// to false routing for installation is enabled.
			Installer::$status = true;
		}
		catch (Exception $e) 
		{
			// In any case where Exception is catched, we can be assure that Installation 
			// is not done/completed, in this case we should use runtime/in-memory setup
			static::$cached['memory'] = Memory::make('runtime.orchestra');

			static::$cached['orchestra_menu']->add('install')->title('Install')->link('orchestra/installer');
		}

		static::$initiated = true;
	}

	/**
	 * Get memory instance for Orchestra
	 *
	 * @static
	 * @access public
	 * @return Hybrid\Memory
	 */
	public static function memory()
	{
		return isset(static::$cached['memory']) ? static::$cached['memory'] : null;
	}

	/**
	 * Get Acl instance for Orchestra
	 *
	 * @static
	 * @access public
	 * @return Hybrid\Acl
	 */
	public static function acl()
	{
		return isset(static::$cached['acl']) ? static::$cached['acl'] : null;
	}

	/**
	 * Get Menu instance for Orchestra
	 *
	 * @static
	 * @access public
	 * @return Hybrid\Acl
	 */
	public static function menu($type = 'orchestra')
	{
		return static::$cached["{$type}_menu"] ?: null;
	}
}