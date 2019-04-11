<?php
    include_once_exists(dirname(__FILE__).'/../../config/config.jml3.php');

    array_push(PluginRegistry::$Classes, 'JoomlaBinding');

    class JoomlaBinding extends Binding
    {
        private static $BindingName = 'jml3';

        public static $HashMethodMD5s = 'jml_md5s';
        public static $HashMethodBF   = 'jml_bf';
        public static $HashMethodMD5r = 'jml_md5r';

        // -------------------------------------------------------------------------

        public function getName()
        {
            return self::$BindingName;
        }

        // -------------------------------------------------------------------------

        public function getConfig()
        {
            $Config = new BindingConfig();

            $Config->Database         = defined('JML3_DATABASE') ? JML3_DATABASE : RP_DATABASE;
            $Config->User             = defined('JML3_USER') ? JML3_USER : RP_USER;
            $Config->Password         = defined('JML3_PASS') ? JML3_PASS : RP_PASS;
            $Config->Prefix           = defined('JML3_TABLE_PREFIX') ? JML3_TABLE_PREFIX : 'jml_';
            $Config->Version          = defined('JML3_VERSION') ? JML3_VERSION : 30000;
            $Config->AutoLoginEnabled = defined('JML3_AUTOLOGIN') ? JML3_AUTOLOGIN : false;
            $Config->CookieData       = defined('JML3_SECRET') ? JML3_SECRET : '0123456789ABCDEF';
            $Config->Members          = defined('JML3_MEMBER_GROUPS') ? explode(',', JML3_MEMBER_GROUPS ) : array();
            $Config->Privileged       = defined('JML3_PRIVILEGED_GROUPS') ? explode(',', JML3_PRIVILEGED_GROUPS ) : array();
            $Config->Raidleads        = defined('JML3_RAIDLEAD_GROUPS') ? explode(',', JML3_RAIDLEAD_GROUPS ) : array();
            $Config->Admins           = defined('JML3_ADMIN_GROUPS') ? explode(',', JML3_ADMIN_GROUPS ) : array();
            $Config->HasCookieConfig  = true;
            $Config->HasGroupConfig   = true;

            return $Config;
        }

        // -------------------------------------------------------------------------

        public function getExternalConfig($aRelativePath)
        {
            $Out = Out::getInstance();

            $ConfigPath  = $_SERVER['DOCUMENT_ROOT'].'/'.$aRelativePath.'/Configuration.php';
            $VersionPath = $_SERVER['DOCUMENT_ROOT'].'/'.$aRelativePath.'/libraries/cms/version/version.php';

            if (!file_exists($ConfigPath))
            {
                $Out->pushError($ConfigPath.' '.L('NotExisting').'.');
                return null;
            }

            @include_once($ConfigPath);

            define('JPATH_PLATFORM', '');
            define('_JEXEC', '');
            @include_once($VersionPath);

            $Version = 30000;
            if (class_exists("JVersion"))
            {
                $VersionClass = new JVersion();
                $VersionParts = explode('.', $VersionClass->RELEASE);
                $Version = intval($VersionParts[0]) * 10000 + intval($VersionParts[1]) * 100 + intval($VersionClass->DEV_LEVEL);
            }

            $Config = new JConfig();

            return array(
                'database'  => $Config->db,
                'user'      => $Config->user,
                'password'  => $Config->password,
                'prefix'    => $Config->dbprefix,
                'cookie'    => $Config->secret,
                'version'   => $Version
            );
        }

        // -------------------------------------------------------------------------

        public function writeConfig($aEnable, $aConfig)
        {
            $Config = fopen( dirname(__FILE__).'/../../config/config.jml3.php', 'w+' );

            fwrite( $Config, "<?php\n");
            fwrite( $Config, "\tdefine('JML3_BINDING', ".(($aEnable) ? "true" : "false").");\n");

            if ( $aEnable )
            {
                fwrite( $Config, "\tdefine('JML3_DATABASE', '".$aConfig->Database."');\n");
                fwrite( $Config, "\tdefine('JML3_USER', '".$aConfig->User."');\n");
                fwrite( $Config, "\tdefine('JML3_PASS', '".$aConfig->Password."');\n");
                fwrite( $Config, "\tdefine('JML3_TABLE_PREFIX', '".$aConfig->Prefix."');\n");
                fwrite( $Config, "\tdefine('JML3_SECRET', '".$aConfig->CookieData."');\n");
                fwrite( $Config, "\tdefine('JML3_AUTOLOGIN', ".(($aConfig->AutoLoginEnabled) ? "true" : "false").");\n");

                fwrite( $Config, "\tdefine('JML3_MEMBER_GROUPS', '".implode( ",", $aConfig->Members )."');\n");
                fwrite( $Config, "\tdefine('JML3_PRIVILEGED_GROUPS', '".implode( ",", $aConfig->Privileged )."');\n");
                fwrite( $Config, "\tdefine('JML3_RAIDLEAD_GROUPS', '".implode( ",", $aConfig->Raidleads )."');\n");
                fwrite( $Config, "\tdefine('JML3_ADMIN_GROUPS', '".implode( ",", $aConfig->Admins )."');\n");
            }

            fwrite( $Config, '?>');

            fclose( $Config );
        }

        // -------------------------------------------------------------------------

        public function getGroups($aDatabase, $aPrefix, $aUser, $aPass, $aThrow)
        {
            $Connector = new Connector(SQL_HOST, $aDatabase, $aUser, $aPass, $aThrow);

            if ($Connector != null)
            {
                $GroupQuery = $Connector->prepare( 'SELECT id, title FROM `'.$aPrefix.'usergroups` ORDER BY title' );
                $Groups = array();

                $GroupQuery->loop(function($Group) use (&$Groups)

                {
                    array_push( $Groups, array(
                        'id'   => $Group['id'],
                        'name' => $Group['title'])
                    );
                }, $aThrow);

                return $Groups;
            }

            return null;
        }

        // -------------------------------------------------------------------------

        public function getForums($aDatabase, $aPrefix, $aUser, $aPass, $aThrow)
        {
            return null;

        }

        // -------------------------------------------------------------------------

        public function getUsers($aDatabase, $aPrefix, $aUser, $aPass, $aThrow)
        {
            return null;

        }

        // -------------------------------------------------------------------------

        private function getGroupForUser( $aUserData )
        {
            // TODO: Banning?

            $Config = $this->getConfig();
            $AssignedGroup = ENUM_GROUP_NONE;

            foreach( $aUserData['Groups'] as $Group )
            {
                $AssignedGroup = $Config->mapGroup($Group, $AssignedGroup);
            }

            return GetGroupName($AssignedGroup);
        }

        // -------------------------------------------------------------------------

        private function generateUserInfo( $aUserData )
        {
            $Info = new UserInfo();
            $Info->UserId      = $aUserData['user_id'];
            $Info->UserName    = $aUserData['username'];
            $Info->Password    = $aUserData['password'];
            $Info->Salt        = $this->extractSaltPart($aUserData['password']);
            $Info->SessionSalt = null;
            $Info->Group       = $this->getGroupForUser($aUserData);
            $Info->BindingName = $this->getName();
            $Info->PassBinding = $this->getName();

            return $Info;
        }

        // -------------------------------------------------------------------------

        public function getExternalLoginData()
        {
            if (!defined('JML3_AUTOLOGIN') || !JML3_AUTOLOGIN)
                return null;

            $UserInfo = null;

            if (defined('JML3_SECRET'))
            {
                // Fetch user info if seesion cookie is set

                $CookieName = md5(md5(JML3_SECRET.'site'));

                if (isset($_COOKIE[$CookieName]))
                {
                    $Connector = $this->getConnector();
                    $UserQuery = $Connector->prepare('SELECT userid '.
                        'FROM `'.JML3_TABLE_PREFIX.'session` '.
                        'WHERE session_id = :sid LIMIT 1');

                    $UserQuery->BindValue( ':sid', $_COOKIE[$CookieName], PDO::PARAM_STR );
                    $UserData = $UserQuery->fetchFirst();

                    if ( $UserData != null )
                    {
                        // Get user info by external id

                        $UserId = $UserData['userid'];
                        $UserInfo = $this->getUserInfoById($UserId);
                    }
                }
            }

            return $UserInfo;
        }

        // -------------------------------------------------------------------------

        public function getUserInfoByName( $aUserName )
        {
            $Connector = $this->getConnector();
            $UserQuery = $Connector->prepare('SELECT user_id, group_id, username, password, activation '.
                                          'FROM `'.JML3_TABLE_PREFIX.'users` '.
                                          'LEFT JOIN `'.JML3_TABLE_PREFIX.'user_usergroup_map` ON id=user_id '.
                                          'WHERE LOWER(username) = :Login');

            $UserQuery->BindValue( ':Login', strtolower($aUserName), PDO::PARAM_STR );

            $UserData = null;
            $Groups = array();

            $UserQuery->loop(function($Data) use (&$UserData, &$Groups)
            {
                $UserData = $Data;
                array_push($Groups, $UserData['group_id']);
            });

            if ($UserData == null)
                return null; // ### return, no users ###

            $UserData['Groups'] = $Groups;
            return $this->generateUserInfo($UserData);
        }

        // -------------------------------------------------------------------------

        public function getUserInfoById( $aUserId )
        {
            $Connector = $this->getConnector();
            $UserQuery = $Connector->prepare('SELECT user_id, group_id, username, password, activation '.
                                             'FROM `'.JML3_TABLE_PREFIX.'users` '.
                                             'LEFT JOIN `'.JML3_TABLE_PREFIX.'user_usergroup_map` ON id=user_id '.
                                             'WHERE id = :UserId');

            $UserQuery->BindValue( ':UserId', $aUserId, PDO::PARAM_INT );
            $UserData = null;
            $Groups = array();

            $UserQuery->loop(function($Data) use (&$UserData, &$Groups)
            {
                $UserData = $Data;
                array_push($Groups, $UserData['group_id']);
            });

            if ($UserData == null)
                return null; // ### return, no users ###

            $UserData['Groups'] = $Groups;
            return $this->generateUserInfo($UserData);
        }

        // -------------------------------------------------------------------------

        private function extractSaltPart( $aPassword )
        {
            global $gItoa64;

            switch ( $this->getMethodFromPass($aPassword) )
            {
            case self::$HashMethodBF:
                return substr($aPassword, 0, 7+22);

            case self::$HashMethodMD5r:
                $Count = strpos($gItoa64, $aPassword[3]);
                $Salt = substr($aPassword, 4, 8);
                return $Count.':'.$Salt;

            default:
            case self::$HashMethodMD5s:
                list($Password,$Salt) = explode(':', $aPassword);
                return $Salt;
            }
        }

        // -------------------------------------------------------------------------

        public function getMethodFromPass( $aPassword )
        {
            if ( strpos($aPassword, '$2y$') === 0 )
                return self::$HashMethodBF;

            if ( strpos($aPassword, '$2a$') === 0 )
                return self::$HashMethodBF;

            if ( strpos($aPassword, '$P$') === 0 )
                return self::$HashMethodMD5r;

            return self::$HashMethodMD5s;
        }

        // -------------------------------------------------------------------------

        public function hash( $aPassword, $aSalt, $aMethod )
        {
            global $gItoa64;

            switch($aMethod)
            {
            case self::$HashMethodMD5s:
                return md5($aPassword.$aSalt).':'.$aSalt;

            case self::$HashMethodMD5r:
                $Parts   = explode(':',$aSalt);
                $CountB2 = intval($Parts[0],10);
                $Count   = 1 << $CountB2;
                $Salt    = $Parts[1];

                $Hash = md5($Salt.$aPassword, true);

                do {
                    $Hash = md5($Hash.$aPassword, true);
                } while (--$Count);

                return '$P$'.$gItoa64[$CountB2].$Salt.encode64($Hash,16);

            default:
                return crypt($aPassword,$aSalt);
            }
        }

        // -------------------------------------------------------------------------

        public function post($aSubject, $aMessage)
        {

        }
    }
?>
