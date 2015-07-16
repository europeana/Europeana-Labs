<?php

/*
 * (c) Aleksey Orlov <i.trancer@gmail.com>
 * (c) Marko Kokol <marko.kokol@semantika.si>
 * (c) Alen Bratanovic <alen.bratanovic@semantika.si>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Bolt\Extension\Semantika\TagCloud
{
    use Bolt\BaseExtension;
    use Bolt\Events\StorageEvents;
    use Bolt\Extension\Semantika\TagCloud\Provider\TagCloudServiceProvider;

    class Extension extends BaseExtension
    {
        public function getName()
        {
            return "tagcloud";
        }
            
        function info()
        {
            $data = array(
                'name' => "TagCloud",
                'description' => "An extension provides capability of tag cloud generation and helpers to display these clouds",
                'keywords' => "bolt, extension, tagcloud",
                'author' => "Aleksey Orlov, Marko Kokol, Alen Bratanovic",
                'link' => "https://github.com/axsy/bolt-extension-tagcloud",
                'version' => "1.0",
                'required_bolt_version' => "2.0.0",
                'highest_bolt_version' => "3.0.0",
                'type' => "General",
                'first_releasedate' => "2013-03-27",
                'latest_releasedate' => "2015-07-07",
                'dependencies' => "",
                'priority' => 10
            );

            return $data;
        }

        function initialize()
        {
            $this->app->register(new TagCloudServiceProvider());
        }
    }
}

namespace Bolt\Extension\Semantika\TagCloud\Provider
{
    use Silex\Application;
    use Silex\ServiceProviderInterface;
    use Axsy\Common\ConfigurationReader;
    use Bolt\Extension\Semantika\TagCloud\Engine\Builder;
    use Bolt\Extension\Semantika\TagCloud\Engine\Configuration;
    use Bolt\Extension\Semantika\TagCloud\Engine\Repository;
    use Bolt\Extension\Semantika\TagCloud\Engine\Storage;
    use Bolt\Extension\Semantika\TagCloud\Engine\TwigExtension;
    use Bolt\Extension\Semantika\TagCloud\Engine\View;
    use Bolt\Events\StorageEvent;
    use Bolt\Events\StorageEvents;

    class TagCloudServiceProvider implements ServiceProviderInterface
    {
        public function register(Application $app)
        {
            $configReader = new ConfigurationReader($app['paths']['apppath'] . '/cache', $app['debug']);
            $app['tagcloud.config'] = $configReader->read(new Configuration(), dirname(__FILE__) . '/config.yml');

            $app['tagcloud.repository'] = $app->share(function ($app) {
                return new Repository($app['db']);
            });
            $app['tagcloud.builder'] = $app->share(function ($app) {
                return new Builder($app['tagcloud.repository'], $app['tagcloud.config'], $app['config']);
            });
            $app['tagcloud.storage'] = $app->share(function ($app) {
                return new Storage($app['tagcloud.builder'], $app['cache']);
            });
            $app['tagcloud.view'] = $app->share(function ($app) {
                return new View($app['tagcloud.storage'], $app['paths']['root']);
            });

            $app['dispatcher']->addListener(StorageEvents::POST_SAVE, function (StorageEvent $event) use ($app) {
                $app['tagcloud.storage']->deleteCloud($event->getContent()->contenttype['slug']);
            });

            $app['twig']->addExtension(new TwigExtension($app['tagcloud.builder'], $app['tagcloud.view']));
        }

        public function boot(Application $app)
        {
        }
    }
}

namespace Bolt\Extension\Semantika\TagCloud\Engine
{
    use Symfony\Component\Config\Definition\Builder\TreeBuilder;
    use Symfony\Component\Config\Definition\ConfigurationInterface;
    use Bolt\Extension\Semantika\TagCloud\Engine\Exception\NoTagsTaxonomiesAvailableException;
    use Bolt\Extension\Semantika\TagCloud\Engine\Exception\NoTaxonomiesAvailableException;
    use Bolt\Extension\Semantika\TagCloud\Engine\Exception\UnknownContentTypeException;
    use Bolt\Extension\Semantika\TagCloud\Engine\Exception\UnsupportedViewException;
    use Doctrine\Common\Cache\CacheProvider;
    use Doctrine\DBAL\Connection;
    use Bolt\Content;
    use PDO;

    interface Exception
    {
    }

    interface StorageInterface
    {
        public function fetchCloud($contentType, $taxonomyName, $taxonomyQuery);
        public function fetchAppCloud($taxonomyQuery, $themeQuery);
        
        public function deleteCloud($contentType);
    }

    interface RepositoryInterface
    {
        public function getIdsForQuery($taxonomyQuery, $contentType, $taxonomyType);
        public function getTaxonomyGroupFor($contentType, $taxonomyType, $taxonomyQuery, $cloudSize);
        public function getTaxonomyGroupForApps($taxonomyQuery, $themeQuery);
    }

    interface BuilderInterface
    {
        public function buildCloudFor($contentType, $taxonomyName, $taxonomyQuery);
        public function buildAppCloudFor($taxonomyQuery, $themeQuery);
        public function buildIdsFor($taxonomyQuery, $contentType, $taxonomyType);
        public function buildAppIdsFor($taxonomyQuery, $themeQuery);
    }

    interface ViewInterface
    {
        public function renderTagArray($taxonomyQuery, $contentType, $taxonomyType);
        public function render($contentType, $taxonomyName, $taxonomyQuery, array $options = array());
        public function renderAppTagArray($taxonomyQuery, $themeQuery);
    }

    class Configuration implements ConfigurationInterface
    {
        public function getConfigTreeBuilder()
        {
            $treeBuilder = new TreeBuilder();
            $rootNode = $treeBuilder->root('tag_cloud');

            $rootNode
                ->children()
                    ->integerNode('size')
                        ->min(1)
                    ->end()
                ->end()
            ;

            return $treeBuilder;
        }
    }

    class Storage implements StorageInterface
    {
        protected $cache;

        public function __construct(Builder $builder, CacheProvider $cache)
        {
            $this->builder = $builder;
            $this->cache = $cache;
        }

        public function fetchCloud($contentType, $taxonomyName, $taxonomyQuery)
        {
            if (!isset($taxonomyName)) {
                $taxonomyName = 'tags';
            }
            if (!isset($taxonomyQuery) || !is_array($taxonomyQuery) || count($taxonomyQuery) == 0) {
                $taxonomyQueryKey = '';
                $useCache = true;
            } else {
                if (count($taxonomyQuery)>3) {
                    //in case we have a complex query (more than 3 items), we do not cache it as they are expected to be fairly uncommon during normal use and the time/memory offset does not pay off any more
                    $useCache=false;
                } else {
                    $taxonomyQueryKey = implode('_', $taxonomyQuery);
                    $useCache=true; 
                }
            }
            
            $cloud = null;
            
            if ($useCache) {
                $key = $this->getKeyFor($contentType . $taxonomyName . $taxonomyQueryKey);
            }   
            
            if ($useCache && $this->cache->contains($key)) {
                $cloud = $this->cache->fetch($key);
            } else {
                $cloud = $this->builder->buildCloudFor($contentType, $taxonomyName, $taxonomyQuery);
                if ($useCache && false !== $cloud) {
                    $this->cache->save($key, $cloud);
                }
            }

            return $cloud;
        }
        
        public function fetchAppCloud($taxonomyQuery, $themeQuery)
        {          
            if (isset($themeQuery)) {
                $taxonomyQueryKey = 'apps_' . $themeQuery;
            } else {
                $taxonomyQueryKey = 'apps_';
            }
            
            if (!isset($taxonomyQuery) || !is_array($taxonomyQuery) || count($taxonomyQuery) == 0) {
                $useCache = true;
            } else {
                if (count($taxonomyQuery)>3) {
                    //in case we have a complex query (more than 3 items), we do not cache it as they are expected to be fairly uncommon during normal use and the time/memory offset does not pay off any more
                    $useCache=false;
                } else {
                    $taxonomyQueryKey = $taxonomyQueryKey . "_" . implode('_', $taxonomyQuery);
                    $useCache=true;
                }
            }
            
            $cloud = null;
            
            if ($useCache) {
                $key = $this->getKeyFor($taxonomyQueryKey);
            }   
            
            if ($useCache && $this->cache->contains($key)) {
                $cloud = $this->cache->fetch($key);
            } else {
                $cloud = $this->builder->buildAppCloudFor($taxonomyQuery, $themeQuery);
                if ($useCache && false !== $cloud) {
                    $this->cache->save($key, $cloud);
                }
            }

            return $cloud;
        }

        protected function getKeyFor($contentType)
        {
            return 'tagcloud_' . $contentType;
        }

        public function deleteCloud($contentType)
        {
            $key = $this->getKeyFor($contentType);
            if ($this->cache->contains($key)) {
                $this->cache->delete($key);
            }
        }
    }

    class Repository implements RepositoryInterface
    {
        protected $conn;

        public function __construct(Connection $conn)
        {
            $this->conn = $conn;
        }

        public function getIdsForQuery($taxonomyQuery, $contentType, $taxonomyType)
        {
            if(is_array($taxonomyQuery) && count($taxonomyQuery) > 0) {
                $tagCount = count($taxonomyQuery);
                $inQuery = implode(',', array_fill(0, count($taxonomyQuery), '?'));
                
                $stmt = $this->conn->prepare(
                    'SELECT content_id
                     FROM bolt_taxonomy
                     WHERE contenttype = ?
                     AND taxonomytype = ?
                     AND slug IN(' . $inQuery . ') ' .'
                     GROUP BY content_id
                     HAVING count(content_id) = ?'
                );

                $stmt->bindValue(1, $contentType);
                $stmt->bindValue(2, $taxonomyType);

                foreach ($taxonomyQuery as $k => $tag) {
                    $stmt->bindValue(($k+3), $tag);
                }
                $stmt->bindValue($tagCount + 3, $tagCount);
                
                $stmt->execute();
                $ids = array();
                while (false !== ($row = $stmt->fetch(PDO::FETCH_NUM))) {
                    array_push($ids, $row[0]);
                }

                return array_unique($ids);
            }
            
            return array();
        }
        
        public function getAppIdsForQuery($taxonomyQuery, $themeQuery)
        {
            if(!is_array($taxonomyQuery)) {
                $taxonomyQuery = array();
            }
            
            if(count($taxonomyQuery) > 0 || isset($themeQuery)) {
                if (isset($themeQuery)) {
                    array_push($taxonomyQuery, $themeQuery);
                }
                
                $tagCount = count($taxonomyQuery);
                $inQuery = implode(',', array_fill(0, count($taxonomyQuery), '?'));

                $stmt = $this->conn->prepare(
                    'SELECT content_id
                     FROM bolt_taxonomy
                     WHERE contenttype = \'apps\'
                     AND taxonomytype IN (\'appthemes\', \'appcategories\')
                     AND slug IN(' . $inQuery . ') ' .'
                     GROUP BY content_id
                     HAVING count(content_id) = ?'
                );

                foreach ($taxonomyQuery as $k => $tag) {
                    $stmt->bindValue(($k+1), $tag);
                }
                $stmt->bindValue($tagCount + 1, $tagCount);
                $stmt->execute();
                
                $ids = array();
                while (false !== ($row = $stmt->fetch(PDO::FETCH_NUM))) {
                    array_push($ids, $row[0]);
                }

                return array_unique($ids);
            }
            
            return array();
        }
        
        public function getTaxonomyGroupFor($contentType, $taxonomyType, $taxonomyQuery, $cloudSize)
        {
            if (!isset($taxonomyQuery) || !is_array($taxonomyQuery) || count($taxonomyQuery) == 0)
            {
                //Get all tags for the given content type, since we have no query tags
                $stmt = $this
                    ->conn
                    ->createQueryBuilder()
                    ->select('bt.slug')
                    ->addSelect('COUNT(bt.id) AS count')
                    ->from('bolt_taxonomy', 'bt')
                    ->groupBy('bt.slug')
                    ->where('bt.taxonomytype = :taxonomyType')
                    ->andWhere('bt.contenttype = :contentType')
                    ->setParameters(array(
                        ':taxonomyType' => $taxonomyType,
                        ':contentType' => $contentType,
                    ))
                    ->setMaxResults($cloudSize)
                    ->execute();
            } else {
                //Retrieve the id of content items that have all of the defined tags
                $ids =  $this->getIdsForQuery($taxonomyQuery, $contentType, $taxonomyType);
                
                if (isset($ids) && is_array($ids)) {
                    $idcount = count($ids);
                } else {
                    $idcount = 0;
                }
                
                if ($idcount == 0) {
                    return array();
                } else {                
                    //Return the counts for the list of the specified IDs
                    $stmt = $this->conn->prepare(
                        'SELECT bt.slug, COUNT(bt.id) AS count
                         FROM bolt_taxonomy bt
                         WHERE bt.contenttype = ?
                         AND bt.taxonomytype = ?
                         AND bt.content_id IN(' . implode(', ', array_fill(0, $idcount, '?')) . ') ' . '
                         GROUP BY bt.slug'
                    );
                    
                    $stmt->bindValue(1, $contentType);
                    $stmt->bindValue(2, $taxonomyType);
                    foreach ($ids as $k => $id) {
                        $stmt->bindValue(($k+3), $id); 
                    }
                    
                    $stmt->execute();
                }
            }
            
            $tags = array();
            while (false !== ($row = $stmt->fetch(PDO::FETCH_NUM))) {
                $tags[$row[0]] = $row[1];
            }

            return $tags;
        }

        public function getTaxonomyGroupForApps($taxonomyQuery, $themeQuery)
        {
            if (!isset($taxonomyQuery) || !is_array($taxonomyQuery)) {
                $taxonomyQuery = array();
            }
            
            if (!isset($themeQuery)) {
                //There is no theme defined - check if we have any active category filters
                if (count($taxonomyQuery) == 0) {
                    //There are no category filters active  - we need to get independent counts for themes and for categories. It makes more (performance) sense to make two relatively simple SQL queries instead of performing a large nested one and calculating results in the app logic

                    //Get the theme counts
                    $stmtThemes = $this->conn->prepare(
                        'SELECT bt2.name, count(bt2.id) AS count FROM bolt_taxonomy AS bt2
                        WHERE contenttype = \'apps\' AND bt2.taxonomytype like \'appthemes\'
                        GROUP BY bt2.name
                        ORDER BY count DESC'
                    );
                    $stmtThemes->execute();

                    //Get the category counts
                    $stmtCategories = $this->conn->prepare(
                        'SELECT bt2.name, count(bt2.id) AS count FROM bolt_taxonomy AS bt2
                        WHERE contenttype = \'apps\' AND bt2.taxonomytype like \'appcategories\'
                        GROUP BY bt2.name
                        ORDER BY count DESC'
                    );
                    $stmtCategories->execute();
                } else {
                    //There are category filters active, but no theme filter. We need to filter only the tags that are included in at least one of the articles that are present in the filter list
                     $stmtThemes = $this->conn->prepare(
                        'SELECT bt2.name, count(bt2.id) AS count FROM bolt_taxonomy AS bt2
                        WHERE contenttype = \'apps\' AND bt2.taxonomytype like \'appthemes\'
                        AND bt2.content_id IN (SELECT bt.content_id
													FROM bolt_taxonomy AS bt
													WHERE contenttype = \'apps\'
													AND taxonomytype = \'appcategories\'
													AND slug IN(' . implode(', ', array_fill(0, count($taxonomyQuery), '?')) . ') ' . '
													GROUP BY content_id
													HAVING count(content_id) = ?
												)
                        GROUP BY bt2.name
                        ORDER BY count DESC'
                    );            
                    
                    foreach ($taxonomyQuery as $k => $tag) {
                        $stmtThemes->bindValue(($k+1), $tag); 
                    }
                    $stmtThemes->bindValue(count($taxonomyQuery)+1, count($taxonomyQuery));
                    $stmtThemes->execute();
                    
                    $stmtCategories = $this->conn->prepare(
                        'SELECT bt2.name, count(bt2.id) AS count FROM bolt_taxonomy AS bt2
                        WHERE contenttype = \'apps\' AND bt2.taxonomytype like \'appcategories\'
                        AND bt2.content_id IN (SELECT bt.content_id
													FROM bolt_taxonomy AS bt
													WHERE contenttype = \'apps\'
													AND taxonomytype = \'appcategories\'
													AND slug IN(' . implode(', ', array_fill(0, count($taxonomyQuery), '?')) . ') ' . '
													GROUP BY content_id
													HAVING count(content_id) = ?
												)
                        GROUP BY bt2.name
                        ORDER BY count DESC'
                    );
                    foreach ($taxonomyQuery as $k => $tag) {
                        $stmtCategories->bindValue(($k+1), $tag); 
                    }
                    $stmtCategories->bindValue(count($taxonomyQuery)+1, count($taxonomyQuery));
                    $stmtCategories->execute();
                }
            } else {
                //We have a theme defined 
                if (count($taxonomyQuery) == 0) {
                    // we have no category filter - since we can only have one category filter, an inner join can be used to get the list of appropriate results
                    //do a self inner join to get all themes that have the theme defined
                    $stmtThemes = $this->conn->prepare(
                        'SELECT bt2.name, count(bt2.id) AS Count FROM bolt_taxonomy AS bt
                        INNER JOIN bolt_taxonomy AS bt2 
                            ON bt.content_id = bt2.content_id 
                                AND bt.taxonomytype like \'appthemes\' AND bt.slug LIKE ?
                                AND bt2.taxonomytype like \'appthemes\'
						WHERE bt.contenttype=\'apps\' AND bt2.contenttype=\'apps\'
                        GROUP BY bt2.name
                        ORDER BY count DESC'
                    );

                    $stmtThemes->bindValue(1, $themeQuery);
                    $stmtThemes->execute();

                    //do a self inner join to get all categories that have the theme defined
                    $stmtCategories = $this->conn->prepare(
                        'SELECT bt2.name, count(bt2.id) AS Count FROM bolt_taxonomy AS bt
                        INNER JOIN bolt_taxonomy AS bt2 
                            ON bt.content_id = bt2.content_id 
                                AND bt.taxonomytype like \'appthemes\' AND bt.slug LIKE ?
                                AND bt2.taxonomytype like \'appcategories\'
						WHERE bt.contenttype=\'apps\' AND bt2.contenttype=\'apps\'
                        GROUP BY bt2.name
                        ORDER BY count DESC'
                    );
                    
                    $stmtCategories->bindValue(1, $themeQuery);
                    $stmtCategories->execute();
                } else {
                    //We have both - a category filter and a theme filter
                     $stmtThemes = $this->conn->prepare(
                        'SELECT bt2.name, count(bt2.id) AS count FROM bolt_taxonomy AS bt
                        INNER JOIN bolt_taxonomy AS bt2 
                            ON bt.content_id = bt2.content_id 
                                AND bt.taxonomytype like \'appthemes\' AND bt.slug LIKE ?
                                AND bt2.taxonomytype like \'appthemes\'
						WHERE bt.contenttype=\'apps\' AND bt2.contenttype=\'apps\'
                        AND bt.content_id IN (SELECT bt3.content_id
													FROM bolt_taxonomy AS bt3
													WHERE contenttype = \'apps\'
													AND taxonomytype = \'appcategories\'
													AND slug IN(' . implode(', ', array_fill(0, count($taxonomyQuery), '?')) . ') ' . '
													GROUP BY content_id
													HAVING count(content_id) = ?
												)
                        GROUP BY bt2.name
                        ORDER BY count DESC'
                    );

                    $stmtThemes->bindValue(1, $themeQuery);
                    foreach ($taxonomyQuery as $k => $tag) {
                        $stmtThemes->bindValue(($k+2), $tag); 
                    }
                    $stmtThemes->bindValue(count($taxonomyQuery)+2, count($taxonomyQuery));
                    $stmtThemes->execute();
                    
                    $stmtCategories = $this->conn->prepare(
                        'SELECT bt2.name, count(bt2.id) AS Count FROM bolt_taxonomy AS bt
                        INNER JOIN bolt_taxonomy AS bt2 
                            ON bt.content_id = bt2.content_id 
                                AND bt.taxonomytype like \'appthemes\' AND bt.slug LIKE ?
                                AND bt2.taxonomytype like \'appcategories\'
						WHERE bt.contenttype=\'apps\' AND bt2.contenttype=\'apps\'
                        AND bt.content_id IN (SELECT bt3.content_id
													FROM bolt_taxonomy AS bt3
													WHERE contenttype = \'apps\'
													AND taxonomytype = \'appcategories\'
													AND slug IN(' . implode(', ', array_fill(0, count($taxonomyQuery), '?')) . ') ' . '
													GROUP BY content_id
													HAVING count(content_id) = ?
												)
                        GROUP BY bt2.name
                        ORDER BY Count DESC'
                    );
                    $stmtCategories->bindValue(1, $themeQuery);
                    foreach ($taxonomyQuery as $k => $tag) {
                        $stmtCategories->bindValue(($k+2), $tag); 
                    }
                    $stmtCategories->bindValue(count($taxonomyQuery)+2, count($taxonomyQuery));
                    $stmtCategories->execute(); 
                }
            }
                        
            $themes = array();
            while (false !== ($row = $stmtThemes->fetch(PDO::FETCH_NUM))) {
                $themes[$row[0]] = $row[1];
            }

            $categories = array();
            while (false !== ($row = $stmtCategories->fetch(PDO::FETCH_NUM))) {
                $categories[$row[0]] = $row[1];
            }
            
            $result = array();
            $result['themes']=$themes;
            $result['categories']=$categories;
            
            return $result;
        }

    }

    class Builder implements BuilderInterface
    {
        protected $config;
        protected $repository;

        public function __construct(Repository $repository, array $cloudConfig, $appConfig)
        {
            $this->cloudConfig = $cloudConfig;
            $this->appConfig = $appConfig;
            $this->repository = $repository;
        }

        public function buildAppCloudFor($taxonomyQuery, $themeQuery)
        {
            $tags = $this->repository->getTaxonomyGroupForApps($taxonomyQuery, $themeQuery);

            if (!empty($tags['themes'])) {
                arsort($tags['themes']);
            }

            if (!empty($tags['categories'])) {
                arsort($tags['categories']);
            }
            return $tags;
        }
        
        public function buildCloudFor($contentType, $taxonomyName, $taxonomyQuery)
        {
            if (!isset($taxonomyName)) {
                $taxonomyName = 'tags';
            }
            
            $tags = $this->repository->getTaxonomyGroupFor($contentType, $taxonomyName, $taxonomyQuery, $this->cloudConfig['size']);

            if (!empty($tags)) {
                arsort($tags);
            }

            return array(
                'taxonomytype' => $taxonomyName,
                'tags' => $tags
            );
        }
        
        public function buildIdsFor($taxonomyQuery, $contentType, $taxonomyType) {
            return $this->repository->getIdsForQuery($taxonomyQuery, $contentType, $taxonomyType);
        }
        
        public function buildAppIdsFor($taxonomyQuery, $themeQuery) {
            return $this->repository->getAppIdsForQuery($taxonomyQuery, $themeQuery);
        }    
    }

    class View implements ViewInterface
    {
        protected $storage;

        public function __construct(Storage $storage, $baseUrl)
        {
            $this->storage = $storage;
            $this->baseUrl = $baseUrl;
        }

        public function renderTagArray($taxonomyQuery, $contentType, $taxonomyType) {
            if (isset($taxonomyQuery) && is_array($taxonomyQuery)) {
                $taxonomyQuery = array_unique($taxonomyQuery);
            }
            
            $cloud = $this->storage->fetchCloud($contentType['slug'], $taxonomyName, $taxonomyQuery);
            
            return $cloud;
        }
        
        public function renderAppTagArray($taxonomyQuery, $themeQuery) {
            if (isset($taxonomyQuery) && is_array($taxonomyQuery)) {
                $taxonomyQuery = array_unique($taxonomyQuery);
            }
            
            $cloud = $this->storage->fetchAppCloud($taxonomyQuery, $themeQuery);
            
            return $cloud;
        }
        public function render($contentType, $taxonomyName, $taxonomyQuery, array $options = array())
        {
            $html = false;
            
            if (isset($taxonomyQuery) && is_array($taxonomyQuery)) {
                $taxonomyQuery = array_unique($taxonomyQuery);
            }
            
            $cloud = $this->storage->fetchCloud($contentType['slug'], $taxonomyName, $taxonomyQuery);

            if (false != $cloud) {
                $options = array_merge($this->getDefaultOptions(), $options);

                if ('raw' != $options['view']) {
                    $html = '<ul';
                    if (!empty($options['list_options']) && is_array($options['list_options'])) {
                        $html .= $this->renderOptions($options['list_options']);
                    }
                    $html .= '>';
                } else {
                    $html = null;
                }
                foreach ($cloud['tags'] as $tag => $rank) {

                    if (isset($taxonomyQuery) && is_array($taxonomyQuery)) {
                        $link = $this->renderLink(
                            $cloud['taxonomytype'], $tag, $rank, $options['marker'], in_array($tag, $taxonomyQuery), $options['link_options']);
                    } else {
                        $link = $this->renderLink(
                            $cloud['taxonomytype'], $tag, $rank, $options['marker'], false, $options['link_options']);
                    }

                    switch ($options['view']) {
                        case 'raw':
                            $html .= "$link ";
                            break;
                        case 'list':
                            $html .= "<li>$link</li>";
                            break;
                        default:
                            throw new UnsupportedViewException($options['view']);
                            break;
                    }
                }
                $html = trim($html) . ('raw' != $options['view'] ? '</ul>' : null);
            }

            return $html;
        }

        public function getDefaultOptions()
        {
            return array(
                'view' => 'list',
                'marker' => 'tag-{rank}',
                'list_options' => null,
                'link_options' => null
            );
        }

        private function renderOptions(array $options)
        {
            $html = null;
            foreach ($options as $key => $value) {
                $html .= " $key=\"" . (is_array($value) ? implode(' ', $value) : trim($value)) . '"';
            }
            return $html;
        }

        private function renderLink($taxonomyType, $tag, $rank, $marker, $isactive, array $options = null)
        {
            if (!isset($options['class'])) {
                $options['class'] = null;
            }
            if (is_array($options['class'])) {
                $options['class'] = implode(' ', $options['class']);
            }
            $options['class'] .= ' ' . str_replace('{rank}', $rank, $marker);
            
            if ($isactive==true) {
                $options['class'] .= ' is-active';
            }

            if (isset($options['root_url'])) {
                if (isset($options['path_url'])) {
                    $usepath = $options['path_url'];
                } else {
                    $usepath = '';
                }
                $html = '<a href="' . sprintf("%s%s%s", $options['root_url'], $options['path_url'], $tag) . '"'
                . $this->renderOptions($options) . '>' . $tag . ' (' . $rank . ')</a>';
            } else {
                $html = '<a href="' . sprintf("%s%s/%s", $this->baseUrl, $taxonomyType, $tag) . '"'
                    . $this->renderOptions($options) . '>' . $tag . ' (' . $rank . ')</a>';
            }
            return $html;
        }
    }

    class TwigExtension extends \Twig_Extension
    {
        protected $builder;
        protected $view;

        public function __construct(Builder $builder, View $view)
        {
            $this->builder = $builder;
            $this->view = $view;
        }

        public function getFunctions()
        {
            return array(
                new \Twig_SimpleFunction('tag_cloud', array($this, 'render'), array('is_safe' => array('html'))),
                new \Twig_SimpleFunction('tag_cloud_raw', array($this, 'renderRaw'), array('is_safe' => array('html'))),
                new \Twig_SimpleFunction('tag_cloud_list', array($this, 'renderList'), array('is_safe' => array('html'))),
                new \Twig_SimpleFunction('tag_cloud_array', array($this, 'getTags')),
                new \Twig_SimpleFunction('apptag_cloud_array', array($this, 'getAppTags'))
            );
        }

        public function getFilters()
        {
            return array(
                new \Twig_SimpleFilter('contenttype', array($this, 'getContentType')),
                new \Twig_SimpleFilter('performtagquery', array($this, 'getRecordIds')),
                new \Twig_SimpleFilter('performapptagquery', array($this, 'getAppRecordIds')),
                new \Twig_SimpleFilter('filteredtags', array($this, 'getTags')),
                new \Twig_SimpleFilter('filteredapptags', array($this, 'getAppTags'))
            );
        }

        public function getContentType($content)
        {
            return $content instanceof Content ? $content->contenttype['slug'] : false;
        }
        
        public function getRecordIds($taxonomyQuery, $contentType, $taxonomyType) {
            return $this->builder->buildIdsFor($taxonomyQuery, $contentType['slug'], $taxonomyType);
        }

        public function getAppRecordIds($taxonomyQuery, $themeQuery) {
            return $this->builder->buildAppIdsFor($taxonomyQuery, $themeQuery);
        }

        public function getTags($taxonomyQuery, $contentType, $taxonomyType) {
            return $this->view->renderTagArray($taxonomyQuery, $contentType, $taxonomyType);
        }

        public function getAppTags($taxonomyQuery, $themeQuery) {
            return $this->view->renderAppTagArray($taxonomyQuery, $themeQuery);
        }

        public function render($contentType, $taxonomyName, $taxonomyQuery, array $options = array())
        {
            return $this->view->renderTagArray($contentType, $taxonomyName, $taxonomyQuery, $options);
        }

        public function renderRaw($contentType, $taxonomyName, $taxonomyQuery, $linkOptions = array(), $marker = null)
        {
            $options = array(
                'view' => 'raw'
            );
            if (!is_null($linkOptions)) {
                $options['link_options'] = $linkOptions;
            }
            if (!is_null($marker)) {
                $options['marker'] = $marker;
            }

            return $this->render($contentType, $taxonomyName, $taxonomyQuery, $options);
        }

        public function renderList($contentType, $taxonomyName, $taxonomyQuery, $linkOptions = array(), $marker = null, $listOptions = array())
        {
            $options = array(
                'view' => 'list'
            );
            if (!is_null($listOptions)) {
                $options['list_options'] = $listOptions;
            }
            if (!is_null($linkOptions)) {
                $options['link_options'] = $linkOptions;
            }
            if (!is_null($marker)) {
                $options['marker'] = $marker;
            }

            return $this->render($contentType, $taxonomyName, $taxonomyQuery, $options);
        }

        public function getName()
        {
            return 'tagcloud';
        }
    }
}

namespace Bolt\Extension\Semantika\TagCloud\Engine\Exception
{
    use Bolt\Extension\Semantika\TagCloud\Engine\Exception;

    class UnsupportedViewException extends \RuntimeException implements Exception
    {
        private $view;

        public function __construct($view)
        {
            $this->view = $view;

            parent::__construct(sprintf('Unknown view mode \'%s\'', $view));
        }

        public function getView()
        {
            return $this->view;
        }
    }
}

namespace Axsy\Common
{
    use Symfony\Component\Config\ConfigCache;
    use Symfony\Component\Config\Definition\ConfigurationInterface;
    use Symfony\Component\Config\Definition\Processor;
    use Symfony\Component\Config\Resource\FileResource;
    use Symfony\Component\Yaml\Yaml;

    class ConfigurationReader
    {
        protected $cachePath;
        protected $debug;

        public function __construct($cachePath, $debug)
        {
            $this->cachePath = $cachePath;
            $this->debug = (bool)$debug;
        }

        public function read(ConfigurationInterface $configuration, $configPath)
        {
            $cacheFile = $this->cachePath . '/extensions/' . pathinfo(dirname(__FILE__), PATHINFO_FILENAME) . '_config.php';
            $cache = new ConfigCache($cacheFile, $this->debug);

            if (!$cache->isFresh()) {
                $processor = new Processor();
                $config = $processor->processConfiguration($configuration, Yaml::parse($configPath));

                $code = sprintf('<?php return unserialize(\'%s\');', serialize($config));
                $cache->write($code, array(new FileResource($configPath)));
            }

            return require_once $cacheFile;
        }
    }
}
