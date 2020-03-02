<?php
/**
 * Class VSPI_Loader - Registers all actions and filters for the plugin.
 *
 * `VSPI_Loader` maintains a list of all hooks that are registered throughout the plugin and registers them
 *  with WordPress. Call the `run` function to execute.
 *
 * @package VSPI/classes
 * @version 1.0.0
 * @author Visit Seattle <webmaster@visitseattle.org>
 */

class VSPI_Loader
{
    /** @var array - A list of actions to register for the plugin. */
	protected $actions;
	/** @var array - A list of filters to register for the plugin. */
	protected $filters;

    /**
     * VSPI_Loader constructor. @constructor
     */
    public function __construct() {
		$this->actions = array();
		$this->filters = array();
	}

	/**
	 * Adds a WordPress action for later registering.
	 *
	 * @param $hook - Name of the action to which the callback should be hooked.
	 * @param $component - Component containing callback.
	 * @param $callback - Name of function to be called when hook is hit.
	 * @param int $priority - Order in which to execute callback. Lower numbers == earlier execution.
	 * @param int $accepted_args - Number of arguments the callback accepts.
	 */
	public function add_action($hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Adds a WordPress filter for later registering.
	 *
	 * @param $hook - Name of the filter to which the callback should be hooked.
	 * @param $component - Component containing the callback.
	 * @param $callback - Name of function to be executed with hook is hit.
	 * @param int $priority - Order in which to execute callback. Lower numbers == earlier execution.
	 * @param int $accepted_args - Number of arguments the callback accepts.
	 */
	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1) {
		$this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
	}

	/**
	 * Formats a filter or action for later registering and adds result to a given object.
	 *
	 * @param $hooks - The object containing the actions or filters to be registered.
	 * @param $hook - Name of the filter to which the callback should be hooked.
	 * @param $component - Component containing the callback.
	 * @param $callback - Name of function to be executed with hook is hit.
	 * @param $priority - Order in which to execute callback. Lower numbers == earlier execution.
	 * @param $accepted_args - Number of arguments the callback accepts.
	 * @return array - The modified object containing all actions or filters.
	 */
	public function add( $hooks, $hook, $component, $callback, $priority, $accepted_args ) {
		$hooks[] = array(
			'hook'  => $hook,
			'component' => $component,
			'callback' => $callback,
			'priority' => $priority,
			'accepted_args' => $accepted_args
		);

		return $hooks;
	}

	/**
	 * Registers with WordPress Loader's pre-constructed lists of filters and actions.
	 */
	public function run() {
		foreach ( $this->filters as $hook ) {
			add_filter(
				$hook['hook'],
				array( $hook['component'], $hook['callback'] ),
				$hook['priority'],
				$hook['accepted_args']
			);
		}

		foreach ($this->actions as $hook ) {
			add_action(
				$hook['hook'],
				array( $hook['component'], $hook['callback'] ),
				$hook['priority'],
				$hook['accepted_args']
			);
		}
	}
}
