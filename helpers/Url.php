<?php

/**
 * This file is part of the {@link http://ontowiki.net OntoWiki} project.
 *
 * @copyright Copyright (c) 2010, {@link http://aksw.org AKSW}
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

/**
 * OntoWiki URL view helper
 *
 * This helper takes a URI and renders it as a link taking into account
 * the route for the current request.
 *
 * @category OntoWiki
 * @copyright Copyright (c) 2010, {@link http://aksw.org AKSW}
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */
class Site_View_Helper_Url extends Zend_View_Helper_Abstract
{
    public function url($uri, $additionalParams = array())
    {
        $url = new OntoWiki_Url(array('route' => 'properties'), array('r'));
        $url->setParam('r', $uri, true);
        
        foreach ($additionalParams as $name => $value) {
            $url->setParam($name, $value, true);
        }
        
        return (string)$url;
    }
}
