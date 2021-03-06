<?php
/**
 * This file is part of the {@link http://ontowiki.net OntoWiki} project.
 *
 * @copyright Copyright (c) 2013, {@link http://aksw.org AKSW}
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

require_once 'MarkupInterface.php';

/**
 * A helper class for the site component.
 *
 * @category   OntoWiki
 * @package    OntoWiki_extensions_components_site
 */
class SiteHelper extends OntoWiki_Component_Helper
{
    /**
     * Name of per-site config file
     */
    const SITE_CONFIG_FILENAME = 'config.ini';

    /**
     * Current site (if in use)
     * @var string|null
     */
    protected $_site = null;

    /**
     * Site config for the current site.
     * @var array
     */
    protected $_siteConfig = null;

    /**
     * Current pseudo file extension.
     * @var string
     */
    protected $_currentSuffix = '';

    public function onPostBootstrap($event)
    {
        $router     = $event->bootstrap->getResource('Router');
        $request    = Zend_Controller_Front::getInstance()->getRequest();
        $controller = $request->getControllerName();
        $action     = $request->getActionName();

        if ($router->hasRoute('empty')) {
            $emptyRoute = $router->getRoute('empty');
            $defaults   = $emptyRoute->getDefaults();

            $defaultController = $defaults['controller'];
            $defaultAction     = $defaults['action'];

            // are we currently following the empty route?
            if ($controller === $defaultController && $action === $defaultAction) {
                /* TODO: this should not be the default site but the site which
                   matches the model of the selected resource */
                $siteConfig = $this->getSiteConfig();

                if (isset($siteConfig['index'])) {
                    // TODO: detect accept header
                    $indexResource = $siteConfig['index'] . $this->getCurrentSuffix();
                    $requestUri    = $this->_config->urlBase
                                   . ltrim($request->getRequestUri(), '/');

                    // redirect if request URI does not match index resource
                    if ($requestUri !== $indexResource) {
                        // response not ready yet, do it the PHP way
                        header('Location: ' . $indexResource, true, 303);
                        exit;
                    }
                }
            }

            $emptyRoute = new Zend_Controller_Router_Route(
                '',
                array(
                    'controller' => 'site',
                    'action'     => $this->_privateConfig->defaultSite
                )
            );
            $router->addRoute('empty', $emptyRoute);
        }
    }

    public function onCreateMenu($event) {
        $request    = Zend_Controller_Front::getInstance()->getRequest();
        $controller = $request->getControllerName();
        $action     = $request->getActionName();

        if ($controller === 'resource' && $action === 'properties') {
            $resourceUri = $this->_owApp->selectedResource;

            if (!empty($resourceUri) && $resourceUri != (string)$this->_owApp->selectedModel) {
                $resourceUri .= '.html';
            }

            $toolbar = OntoWiki_Toolbar::getInstance();
            $toolbar->prependButton(OntoWiki_Toolbar::SEPARATOR)
                    ->prependButton(
                        OntoWiki_Toolbar::SUBMIT,
                        array(
                            'name' => 'Back to Site',
                            'url' => $resourceUri
                        )
                    );
        }
    }

    public function onShouldLinkedDataRedirect($event)
    {
        if ($event->type) {
            // Supported type?
            $requestUri = $event->request->getServer('REQUEST_URI');
            $parts = explode('.', $requestUri);
            if ($parts[count($parts)-1] != $event->type) {
                header('Location: ' . $event->uri . '.' . $event->type, true, 302);
                exit;
            }
        } else {
            return false;
        }

        if ($event->type === 'html') {
            $event->request->setControllerName('site');
            $event->request->setActionName($this->_privateConfig->defaultSite);

            if ($event->flag) {
                $this->_currentSuffix = '.html';
            }
        } else {
            // export
            $event->request->setControllerName('resource');
            $event->request->setActionName('export');
            $event->request->setParam('f', $event->type);
            $event->request->setParam('r', $event->uri);
        }

        $event->request->setDispatched(false);
        return false;
    }

    public function onIsDispatchable($event)
    {
        if (!$event->getValue()) {
            // linked data plug-in returned false --> 404
            $config = $this->getSiteConfig();
            if (isset($config['error'])) {
                $errorResource = $config['error'];

                if (isset($config['model'])) {
                    $siteGraph = $config['model'];

                    $store = OntoWiki::getInstance()->erfurt->getStore();
                    $siteModel = $store->getModel($siteGraph);

                    $sparql = sprintf('ASK FROM <%s> WHERE {<%s> ?p ?o .}', $siteGraph, $errorResource);
                    $query  = Erfurt_Sparql_SimpleQuery::initWithString($sparql);
                    $result = $store->sparqlAsk($query);
                    if (true === $result) {
                        OntoWiki::getInstance()->selectedModel    = $siteModel;
                        OntoWiki::getInstance()->selectedResource = new OntoWiki_Resource($errorResource, $siteModel);

                        $request = Zend_Controller_Front::getInstance()->getRequest();
                        $request->setControllerName('site');
                        $request->setActionName($this->_privateConfig->defaultSite);

                        return true;
                    }
                    else {
                        $response = Zend_Controller_Front::getInstance()->getResponse();
                        $response->setRawHeader('HTTP/1.0 404 Not Found');
                    }
                }

            }

            return false;

            /*
             * TODO:
             * if error is 404
             * 1. set 404 header
             * if error resource exists in site model
             *   2. load site model
             *   3. set error resource as current resource
             *   4. render site as normal
             * fi
             * else
             *   2. render default 404 template
             */

            // $errorHandler = Zend_Controller_Front::getInstance()->getPlugin('Zend_Controller_Plugin_ErrorHandler');
            // if ($errorHandler) {
            //     $errorHandler->setErrorHandler(array(
            //         'controller' => 'site',
            //         'action' => 'error'
            //     ));
            // }
        }
    }

    /**
     * This method is calles when a OntoWiki_Url is renderd to a string.
     *
     * @param $event the event object containing necessary parameters
     * @return boolean true if the URL was create, false if not
     */
    public function onBuildUrl($event)
    {
        $site = $this->getSiteConfig();

        if (isset($site['model'])) {
            $graph = $site['model'];
        } else {
            $graph = null;
        }

        if(isset($event->params['r'])) {
            $resource = OntoWiki_Utils::expandNamespace($event->params['r']);
        } else {
            $resource = null;
        }

        // URL for this site?
        if (($graph === (string)OntoWiki::getInstance()->selectedModel) && !empty($this->_site)) {
            // check if the requested resource is in the namespace of the graph to see if it can be
            // deliverd as LinkedData
            if (false !== strpos($resource, $graph)) {
                // LD-capable
                if ((string) $resource[strlen($resource)-1] == '/') {
                    // Slash should not get a suffix
                    $event->url = (string) $resource;
                    return true;
                } else {
                    $event->url = $resource
                            . $this->getCurrentSuffix();
                    return true;
                }
            } else {
                // check if the controller and action are set explicitly or not
                if (empty($event->controller) && empty($event->action)) {
                    // set the controller and action according to the site to let the site handle
                    // the URL
                    $event->route      = null;
                    $event->controller = 'site';
                    $event->action     = $site['id'];
                }
                return false;
            }
        }
    }

    public function getSiteConfig()
    {
        if (null === $this->_siteConfig) {
            $this->_siteConfig = array();
            $site = $this->_privateConfig->defaultSite;

            $relativeTemplatePath = OntoWiki::getInstance()->extensionManager->getExtensionConfig('site')->templates;
            // load the site config
            $configFilePath = sprintf('%s/%s/%s/%s', $this->getComponentRoot(), $relativeTemplatePath, $site, self::SITE_CONFIG_FILENAME);
            if (is_readable($configFilePath)) {
                if ($config = parse_ini_file($configFilePath, true)) {
                    $this->_siteConfig = $config;
                }
            }

            // add the site id to the config in order to allow correct URIs
            $this->_siteConfig['id'] = $site;
        }

        return $this->_siteConfig;
    }

    public function getCurrentSuffix()
    {
        return $this->_currentSuffix;
    }

    public static function skosNavigationAsArray($titleHelper)
    {
        $store = OntoWiki::getInstance()->erfurt->getStore();
        $model = OntoWiki::getInstance()->selectedModel;

        $query = '
            PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
            PREFIX sysont: <http://ns.ontowiki.net/SysOnt/>
            SELECT ?topConcept ?altLabel
            FROM <' . (string)$model . '>
            WHERE {
                ?cs a skos:ConceptScheme .
                ?topConcept skos:topConceptOf ?cs
                OPTIONAL {
                    ?topConcept sysont:order ?order
                }
                OPTIONAL {
                    ?topConcept skos:altLabel ?altLabel
                }
            }
            ORDER BY ASC(?order)
            ';

        if ($result = $store->sparqlQuery($query)) {
            $tree = array();
            foreach ($result as $row) {
                $topConcept = new stdClass;
                $topConcept->uri = $row['topConcept'];

                $titleHelper->addResource($topConcept->uri);

                if ($row['altLabel'] != null) {
                    $topConcept->altLabel = $row['altLabel'];
                }

                $subQuery = '
                    PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
                    PREFIX sysont: <http://ns.ontowiki.net/SysOnt/>
                    SELECT ?subConcept ?altLabel
                    FROM <' . (string)$model . '>
                    WHERE {
                        ?subConcept skos:broader <' . $topConcept->uri . '>
                        OPTIONAL {
                            ?subConcept sysont:order ?order
                        }
                        OPTIONAL {
                            ?subConcept skos:altLabel ?altLabel
                        }
                    }
                    ORDER BY ASC(?order)
                    ';
                if ($subConceptsResult = $store->sparqlQuery($subQuery)) {
                    $subconcepts = array();
                    foreach ($subConceptsResult as $subConceptRow) {
                        $subConcept = new stdClass;
                        $subConcept->uri = $subConceptRow['subConcept'];
                        $subConcept->subconcepts = array();

                        if ($subConceptRow['altLabel'] != null) {
                            $subConcept->altLabel = $subConceptRow['altLabel'];
                        }

                        $titleHelper->addResource($subConcept->uri);
                        $subconcepts[$subConcept->uri] = $subConcept;
                    }
                    $topConcept->subconcepts = $subconcepts;
                } else {
                    $topConcept->subconcepts = array();
                }

                $tree[$topConcept->uri] = $topConcept;
            }

            return $tree;
        }

        return array();
    }

    public function setSite($site)
    {
        $this->_site = (string)$site;
    }

}
