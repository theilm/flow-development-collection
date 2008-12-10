<?php
declare(ENCODING = 'utf-8');
namespace F3\FLOW3\MVC\View;

/*                                                                        *
 * This script is part of the TYPO3 project - inspiring people to share!  *
 *                                                                        *
 * TYPO3 is free software; you can redistribute it and/or modify it under *
 * the terms of the GNU General Public License version 2 as published by  *
 * the Free Software Foundation.                                          *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General      *
 * Public License for more details.                                       *
 *                                                                        */

/**
 *
 * @package FLOW3
 * @subpackage MVC
 * @version $Id:\F3\FLOW3\MVC\View\Empty.php 467 2008-02-06 19:34:56Z robert $
 */

/**
 * An empty view - a special case.
 *
 * @package FLOW3
 * @subpackage MVC
 * @version $Id:\F3\FLOW3\MVC\View\Empty.php 467 2008-02-06 19:34:56Z robert $
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License, version 2
 */
class EmptyView extends \F3\FLOW3\MVC\View\AbstractView {

	/**
	 * Renders the empty view
	 *
	 * @return string An empty string
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function render() {
		return '';
	}

	/**
	 * A magic call method.
	 *
	 * Because this empty view is used as a Special Case in situations when no matching
	 * view is available, it must be able to handle method calls which originally were
	 * directed to another type of view. This magic method should prevent PHP from issuing
	 * a fatal error.
	 *
	 * @return void
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function __call($methodName, array $arguments) {
	}
}
?>