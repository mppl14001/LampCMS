<?php
/**
 *
 * License, TERMS and CONDITIONS
 *
 * This software is licensed under the GNU LESSER GENERAL PUBLIC LICENSE (LGPL) version 3
 * Please read the license here : http://www.gnu.org/licenses/lgpl-3.0.txt
 *
 *  Redistribution and use in source and binary forms, with or without
 *  modification, are permitted provided that the following conditions are met:
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 3. The name of the author may not be used to endorse or promote products
 *    derived from this software without specific prior written permission.
 *
 * ATTRIBUTION REQUIRED
 * 4. All web pages generated by the use of this software, or at least
 *       the page that lists the recent questions (usually home page) must include
 *    a link to the http://www.lampcms.com and text of the link must indicate that
 *    the website\'s Questions/Answers functionality is powered by lampcms.com
 *    An example of acceptable link would be "Powered by <a href="http://www.lampcms.com">LampCMS</a>"
 *    The location of the link is not important, it can be in the footer of the page
 *    but it must not be hidden by style attributes
 *
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR "AS IS" AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE FREEBSD PROJECT OR CONTRIBUTORS BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF
 * THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This product includes GeoLite data created by MaxMind,
 *  available from http://www.maxmind.com/
 *
 *
 * @author     Dmitri Snytkine <cms@lampcms.com>
 * @copyright  2005-2012 (or current year) ExamNotes.net inc.
 * @license    http://www.gnu.org/licenses/lgpl-3.0.txt GNU LESSER GENERAL PUBLIC LICENSE (LGPL) version 3
 * @link       http://www.lampcms.com   Lampcms.com project
 * @version    Release: @package_version@
 *
 *
 */


namespace Lampcms\Uri;

use Lampcms\Utf8String;
use Lampcms\DevException;

/**
 * Class for mapping URL parts to their values
 * Mapping is set in the !config.ini in the [URL_PARTS] section
 *
 * @author Dmitri Snytkine
 *
 */
class Router
{

    /**
     * These strings are not allowed
     * values because they are used internally
     * as start of placeholders of variables for routes and translation strings
     *
     * @var array
     */
    protected $filterSearch = array('{_', '@@');

    /**
     * Strings from filterSearch will be replaced with
     * their html entities
     *
     * @var array
     */
    protected $filterReplace = array('&#123;_', '&#64;@');

    /**
     * URI string
     *
     * @var string
     */
    protected $uri = '';

    /**
     * Array of uri segments
     *
     * @var array
     */
    protected $uriSegments = array();

    /**
     * Controller name resolved from uri string
     * using [ROUTES] map in !config.ini
     * or a _DEFAULT_ controller
     *
     * @var string
     */
    protected $controller;


    /**
     * In case uri string contains pagination
     * this var will be set to page number
     *
     * @var int
     */
    protected $pageID;

    /**
     *
     * @var array
     */
    protected $map;

    /**
     * Config\Ini object
     *
     * @var \Lampcms\Config\Ini
     */
    protected $Ini;

    protected $routes = array();

    protected $callback;

    /**
     * Constructor
     *
     * @param \Lampcms\Config\Ini $Ini
     */
    public function __construct(\Lampcms\Config\Ini $Ini)
    {
        $this->Ini    = $Ini;
        $this->map    = $Ini->getSection('URI_PARTS');
        $this->routes = $Ini->getSection('ROUTES');
        $this->init();
    }


    /**
     * Getter for $this->uriSegments
     *
     * @return array numeric array of uri segments
     */
    public function getUriSegments()
    {
        return $this->uriSegments;
    }


    /**
     * Different from getPageID
     * In the prefix and postfix
     * of last segment do not match pagination
     * prefix and postfix then it will generate
     * a rewrite exception.
     * This is safe to use ONLY of viewquestions, unanswered, etc
     * Do no use this to extract page id
     * on pages that may contain tag names
     * because if tag name contains number (my25cents), then this function
     * will decide that 25 is a page number and will redirect
     * to the page25.html
     *
     * @return int
     */
    public function getRealPageID()
    {
        if (!isset($this->pageID)) {

            $segmentId = count($this->uriSegments);
            /**
             * Special case
             * no segments - this is home page
             * in this case pageID is always 1
             */
            if (0 === $segmentId) {
                return 1;
            }

            $this->pageID = $this->getNumber($segmentId, 1, $this->map['PAGER_PREFIX'], $this->map['PAGER_EXT']);
        }

        return $this->pageID;
    }


    /**
     * Extract page id from the segments
     *
     * @param int $segmentId if supplied then will look
     *                       for pageID in this segment number. Otherwise looks for page
     *                       id in the last segment
     *
     * @return int page id, always defaults to 1
     */
    public function getPageID($segmentId = null)
    {
        if (!isset($this->pageID)) {
            $segmentId = (is_numeric($segmentId)) ? $segmentId : count($this->uriSegments);
            /**
             * Special case
             * no segments - this is home page
             * in this case pageID is always 1
             */
            if (0 === $segmentId) {
                return 1;
            }


            $lastSegment = $this->getSegment($segmentId, 's');

            \preg_match('/' . $this->map['PAGER_PREFIX'] . '(\d+)' . $this->map['PAGER_EXT'] . '/i', $lastSegment, $matches);

            $this->pageID = (is_array($matches) && !empty($matches[1])) ? (int)$matches[1] : 1;
        }

        return $this->pageID;
    }


    /**
     * Get the value of the segment
     *
     * @param int     $id      Number of segment (starts with 1 for the first segnemt, 2 for second, etc)
     * @param string  $type    Indicates the type of return value. One of 's' for string, 'i' for int or 'b' for bool
     * @param mixed   $default What value to return when segment not found
     *
     * @throws SegmentNotFoundException
     * @throws SegmentException
     * @throws \Lampcms\DevException if param $id is not an integer
     *
     * @return bool|int|string
     */
    public function getSegment($id, $type = 's', $default = null)
    {

        if (!is_int($id) || ($id < 1)) {
            throw new DevException('Value of $id param must be integer > 0 was: ' . gettype($id) . ' ' . $id);
        }

        $id = $id - 1;

        if (!isset($this->controller)) {
            $this->init();
        }

        if (!\array_key_exists($id, $this->uriSegments)) {
            if (null === $default) {
                throw new SegmentNotFoundException('Segment ' . $id . ' not found. Segments: ' . print_r($this->uriSegments, 1));
            }

            $val = $default;
        } else {
            $val = $this->uriSegments[$id];
        }

        /**
         * We can't allow {_ and @@ in the input because we use them as our
         * placeholder opening strings. If we allow these then anyone could
         * inject placeholder into the input by simply passing something
         * like {_WEB_ROOT_}
         * This could be a legitimate case when someone wants to search
         * for {_WEB_ROOT_} string.
         *
         */
        $val = \str_replace($this->filterSearch, $this->filterReplace, $val);

        switch ( $type ) {

            case 'b':
                $val = (bool)$val;
                break;

            case 'i':
                /**
                 * Special case if requesting value as integer
                 * then must extract the integer from segment instead of
                 * simply converting to (int) because converting string like 'question5' to int will result in 0
                 *
                 */
                if ($val !== $default) {
                    $val = \preg_replace("/\D/", "", $val);
                }
                if (0 !== $val && empty($val)) {
                    if (null !== $default) {
                        return (int)$default;
                    }

                    throw new SegmentException('Segment ' . $id . ' does not contain a number. Segments: ' . print_r($this->uriSegments, 1));
                }
                $val = (int)$val;
                break;

            default:
                $val = (string)$val;
        }

        return $val;
    }


    /**
     * Get value of segment as a Utf8String object
     *
     * @param      $segmentId
     * @param null $default
     *
     * @return object of type \Lampcms\Utf8String
     */
    public function getUTF8($segmentId, $default = null)
    {
        $res = $this->getSegment($segmentId, 's', $default);
        $ret = Utf8String::stringFactory($res);

        return $ret;
    }


    /**
     * @param int     $id      segment number
     * @param null    $default default value to return if segment does not exist or number not in segment
     * @param null    $prefix  optional prefix before the number in the segment
     * @param null    $postfix optional postfix before the number in the segment
     *
     * @throws \Lampcms\RedirectException
     * @throws SegmentException
     * @return int
     */
    public function getNumber($id, $default = null, $prefix = null, $postfix = null)
    {
        $segment = $this->getSegment($id, 's', $default);

        \preg_match('/(\D*)(\d+)(\D*)/u', $segment, $matches);

        if (empty($matches) || !\array_key_exists(2, $matches)) {
            if (is_int($default)) {
                return $default;
            }

            throw new SegmentException('Number not found in segment ' . $id . ' uri: ' . $this->uri);
        }

        $int = $matches[2];

        if ($segment !== $prefix . $int . $postfix) {
            $segments            = $this->uriSegments;
            $segments[($id - 1)] = $prefix . $int . $postfix;
            $uri                 = \implode('/', $segments);
            $uri                 = $this->Ini->SITE_URL . $this->map['DIR'] . $this->map['FILE'] . '/' . $this->getControllerSegment() . '/' . $uri;

            if (!empty($_SERVER['QUERY_STRING'])) {
                $uri .= '?' . $_SERVER['QUERY_STRING'];
            }

            throw new \Lampcms\RedirectException($uri);
        }

        return (int)$int;
    }


    /**
     * Get the name of controller that should be used
     * as the 1st uri segment.
     * If the rule is defined in [ROUTES] then it will be the alias
     * of the controller, othewise the $this->controller
     *
     *
     * @return string string that can be used as first section of the uri
     */
    public function getControllerSegment()
    {
        if (\in_array($this->controller, $this->routes)) {
            return \array_search($this->controller, $this->routes);
        }

        return $this->controller;
    }


    /**
     * Getter for $this->controller
     *
     * @return string name of controller
     */
    public function getController()
    {
        return \Lampcms\Request::getCleanControllerName($this->controller);
    }


    /**
     *
     * Get name of controller from the uri string
     * and set it as $this->controller
     * Also sets up the value of $this->uriSegments array
     * with the first segment being the next segment after the controller
     * (in other words the controller segment is not included in uriSegments array)
     *
     * @param mixed string|null $uri
     *
     * @throws \OutOfBoundsException in case a redirect is made the controller alias
     * @throws \InvalidArgumentException if $uri param is passed and is not a string
     * @return string value of controller resolved from URL and
     * mapped using ROUTES section in !config.ini
     * or name of first segment or uri
     * or default controller
     */
    public function init($uri = null)
    {
        if (null !== $uri && !is_string($uri)) {
            throw new \InvalidArgumentException('Invalid value of $uri param. Must be string. Was: ' . gettype($uri));
        }

        if (empty($uri)) {
            $uri = UriString::getUriString();
        }

        if ('' !== $uri) {

            $this->uri = $uri;

            foreach ($this->routes as $route => $controller) {
                $strlen = \strlen($route);
                /**
                 * Do case-insensitive search for a matching route
                 */
                if (($route === \strtolower($this->uri)) || (0 === \strncasecmp($route . '/', $this->uri, ($strlen + 1)))) {

                    $this->controller = $controller;

                    if (\strlen($this->uri) > $strlen) {
                        $this->makeSegments(\substr($this->uri, $strlen));
                    }

                    return $this->controller;
                }
            }
        }


        /**
         * We have not matched any of the routes defined
         * in [ROUTES] in !config.ini
         * Now if there are any segments then the controller
         * is going to be the first segment lower-cased
         * otherwise if there are no segments (in case where the uri string is empty)
         * return default controller
         */
        $this->makeSegments($this->uri);
        if (!empty($this->uriSegments)) {
            $this->controller = \mb_strtolower(\array_shift($this->uriSegments));
            /**
             *
             * If admin adds or modifies a route alias
             * after the site has been running for awhile, the link to old (original)
             * route may already be bookmarked by someone or found in search engines
             * In this case we want to redirect the request to the alias url
             */
            if (\in_array($this->controller, $this->routes)) {
                $newUrl  = \preg_replace('/' . $this->controller . '/i', \array_search($this->controller, $this->routes), $this->uri, 1);
                $fullUrl = $this->Ini->SITE_URL . $this->map['DIR'] . $this->map['FILE'] . '/' . $newUrl;
                header("Location: " . $fullUrl, true, 301);
                throw new \OutOfBoundsException;
            }
        } else {
            $this->controller = $this->Ini->DEFAULT_CONTROLLER;
        }

        return $this;
    }


    /**
     * Explode the $uri string and create the
     * $this->uriSegments array
     *
     * @param $uri
     *
     * @return    object $this
     */
    protected function makeSegments($uri)
    {
        if (!empty($uri)) {
            // this is an alternative to trim() but will trim one or more slashes
            $t = \preg_replace('|/*(.+?)/*$|', '\\1', $uri);

            foreach (explode('/', $t) as $val) {

                $val = \trim($val);

                if ($val != '') {
                    $this->uriSegments[] = $val;
                }
            }
        }

        return $this;
    }


    /**
     * Get the callback "mapper" function
     * this function will replace all route placeholders
     * in html with the route or route alias as defined in ROUTES
     * in !config.ini
     * This callback is used in the output buffer
     *
     * @return closure a function that will replace
     * placeholders in string with the values from URL_PARTS
     * section
     */
    public function getCallback()
    {
        if (!isset($this->callback)) {
            $search = $replace = array();
            foreach ($this->map as $k => $v) {
                $search[]  = '{_' . $k . '_}';
                $replace[] = $v;
            }

            /**
             * Add extra search/replace for {_ROOT_FILE_}
             * Which is a WEB_ROOT.ROOT_FILE
             */
            $search[]  = '{_WEB_ROOT_}';
            $replace[] = $this->map['DIR'] . $this->map['FILE'];

            $search[]  = '{_FORM_ACTION_}';
            $replace[] = $this->map['DIR'] . '/' . INDEX_FILE;

            $siteUrl = $this->Ini->SITE_URL;

            /**
             * Now add controller name as search
             * and route as replace values
             */
            foreach ($this->routes as $route => $controller) {
                $search[]  = '{_' . $controller . '_}';
                $replace[] = $route;
            }

            $this->callback = function($s, $fullUrl = true) use ($search, $replace, $siteUrl)
            {
                //$siteUrl = ($fullUrl) ? $siteUrl : '';
                /**
                 * First replace all alias values
                 * with their real values
                 */
                $res = \str_replace($search, $replace, $s);

                /**
                 * Now replace all controller and uri based placeholders
                 * with their own values (from between {_ and _}
                 */
                return preg_replace('/{_([a-zA-Z0-9_\-]+)_}/', '\\1', $res);

            };
        }

        return $this->callback;
    }


    /**
     * Get full url of the question
     * This is convenience method and it is used often from
     * various email methods as well as from modules that post
     * question to Twitter, Facebook, etc. where a full url is needed
     *
     * @param   int     $questionId
     * @param string    $slug optionally a title slug
     *
     * @return string a full url of the question
     */
    public function getQuestionUrl($questionId, $slug = '')
    {

        $uri    = $this->Ini->SITE_URL . '{_WEB_ROOT_}/{_viewquestion_}/{_QID_PREFIX_}' . $questionId . '/' . $slug;
        $mapper = $this->getCallback();
        $ret    = $mapper($uri);

        return $ret;
    }


    /**
     * Get full url to the site
     *
     * @return string full url of QA site (including web directory if installed in non-root directory
     * and including /index.php if not using server-side rewrite rules)
     */
    public function getHomePageUrl(){
        $uri    = $this->Ini->SITE_URL . '{_WEB_ROOT_}';
        $mapper = $this->getCallback();
        $ret    = $mapper($uri);

        return $ret;
    }

}
