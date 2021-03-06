<?php


if (!defined('AOWOW_REVISION'))
    die('illegal access');


    // note: for the sake of simplicity, this function handles all whole images (which are mostly icons)
    // quest icons from GossipFrame have an alphaChannel that cannot be handled by this script
    // lfgFrame/lfgIcon*.blp .. candidates for zonePage, but in general too detailed to scale them down from 128 to 56, 36, ect
    function simpleImg()
    {
        if (isset(FileGen::$cliOpts['help']))
        {
            echo "\n";
            echo "available Options for subScript 'simpleImg':\n";
            echo " --icons          (generates square icons that are used for basicly everything)\n";
            echo " --glyphs         (decorative tidbit displayed on Infobox for Glyph Spells)\n";
            echo " --pagetexts      (imagery contained in PageTexts on readable GameObjects or Items)\n";
            echo " --loadingscreens (loadingscreens (not used, skipped by default))\n";

            return true;
        }

        if (!class_exists('DBC'))
        {
            FileGen::status(' - simpleImg: required class DBC was not included', MSG_LVL_ERROR);
            return false;
        }

        if (!function_exists('imagecreatefromblp'))
        {
            FileGen::status(' - simpleImg: required include imagecreatefromblp() was not included', MSG_LVL_ERROR);
            return false;
        }

        $locStr   = '';
        $groups   = [];
        $dbcPath  = FileGen::$srcDir.'%sDBFilesClient/';
        $imgPath  = FileGen::$srcDir.'%sInterface/';
        $destDir  = 'static/images/wow/';
        $success  = true;
        $iconDirs = array(
            ['icons/large/',  'jpg',  0, 56, 4],
            ['icons/medium/', 'jpg',  0, 36, 4],
            ['icons/small/',  'jpg',  0, 18, 4],
            ['icons/tiny/',   'gif',  0, 15, 4]
        );
        $calendarDirs = array(
            ['icons/large/',  'jpg', 90, 56, 4],
            ['icons/medium/', 'jpg', 90, 36, 4],
            ['icons/small/',  'jpg', 90, 18, 4],
            ['icons/tiny/',   'gif', 90, 15, 4]
        );
        $loadScreenDirs = array(
            ['loadingscreens/large/',    'jpg', 0, 1024, 0],
            ['loadingscreens/medium/',   'jpg', 0,  488, 0],
            ['loadingscreens/original/', 'png', 0,    0, 0],
            ['loadingscreens/small/',    'jpg', 0,  244, 0]
        );
        $paths    = array(                                  // src, [dest, ext, srcSize, destSize, borderOffset], pattern, isIcon, tileSize
             0 => ['Icons/',                                                $iconDirs,                                       '/*.[bB][lL][pP]',                true,   0],
             1 => ['Spellbook/',                                            [['Interface/Spellbook/',     'png', 0,  0, 0]], '/UI-Glyph-Rune*.blp',            true,   0],
             2 => ['PaperDoll/',                                            array_slice($iconDirs, 0, 3),                    '/UI-{Backpack,PaperDoll}-*.blp', true,   0],
             3 => ['GLUES/CHARACTERCREATE/UI-CharacterCreate-Races.blp',    $iconDirs,                                       '',                               true,  64],
             4 => ['GLUES/CHARACTERCREATE/UI-CharacterCreate-CLASSES.blp',  $iconDirs,                                       '',                               true,  64],
             5 => ['GLUES/CHARACTERCREATE/UI-CharacterCreate-Factions.blp', $iconDirs,                                       '',                               true,  64],
          // 6 => ['Minimap/OBJECTICONS.BLP',                               [['icons/tiny/',              'gif', 0, 16, 2]], '',                               true,  32],
             7 => ['FlavorImages/',                                         [['Interface/FlavorImages/',  'png', 0,  0, 0]], '/*.[bB][lL][pP]',                false,  0],
             8 => ['Pictures/',                                             [['Interface/Pictures/',      'png', 0,  0, 0]], '/*.[bB][lL][pP]',                false,  0],
             9 => ['PvPRankBadges/',                                        [['Interface/PvPRankBadges/', 'png', 0,  0, 0]], '/*.[bB][lL][pP]',                false,  0],
            10 => ['Calendar/Holidays/',                                    $calendarDirs,                                   '/*{rt,a,y,h,s}.[bB][lL][pP]',    true,   0],
            11 => ['GLUES/LOADINGSCREENS/',                                 $loadScreenDirs,                                 '/[lL][oO]*.[bB][lL][pP]',        false,  0]
        );
        // textures are composed of 64x64 icons
        // numeric indexed arrays mimick the position on the texture
        $cuNames = array(
            2 => array(
                'ui-paperdoll-slot-chest'         => 'inventoryslot_chest',
                'ui-backpack-emptyslot'           => 'inventoryslot_empty',
                'ui-paperdoll-slot-feet'          => 'inventoryslot_feet',
                'ui-paperdoll-slot-finger'        => 'inventoryslot_finger',
                'ui-paperdoll-slot-hands'         => 'inventoryslot_hands',
                'ui-paperdoll-slot-head'          => 'inventoryslot_head',
                'ui-paperdoll-slot-legs'          => 'inventoryslot_legs',
                'ui-paperdoll-slot-mainhand'      => 'inventoryslot_mainhand',
                'ui-paperdoll-slot-neck'          => 'inventoryslot_neck',
                'ui-paperdoll-slot-secondaryhand' => 'inventoryslot_offhand',
                'ui-paperdoll-slot-ranged'        => 'inventoryslot_ranged',
                'ui-paperdoll-slot-relic'         => 'inventoryslot_relic',
                'ui-paperdoll-slot-shirt'         => 'inventoryslot_shirt',
                'ui-paperdoll-slot-shoulder'      => 'inventoryslot_shoulder',
                'ui-paperdoll-slot-tabard'        => 'inventoryslot_tabard',
                'ui-paperdoll-slot-trinket'       => 'inventoryslot_trinket',
                'ui-paperdoll-slot-waist'         => 'inventoryslot_waist',
                'ui-paperdoll-slot-wrists'        => 'inventoryslot_wrists'
            ),
            3 => array(                                     // uses nameINT from ChrRaces.dbc
                ['race_human_male',    'race_dwarf_male',     'race_gnome_male',   'race_nightelf_male',   'race_draenei_male'   ],
                ['race_tauren_male',   'race_scourge_male',   'race_troll_male',   'race_orc_male',        'race_bloodelf_male'  ],
                ['race_human_female',  'race_dwarf_female',   'race_gnome_female', 'race_nightelf_female', 'race_draenei_female' ],
                ['race_tauren_female', 'race_scourge_female', 'race_troll_female', 'race_orc_female',      'race_bloodelf_female']
            ),
            4 => array(                                     // uses nameINT from ChrClasses.dbc
                ['class_warrior', 'class_mage',       'class_rogue',  'class_druid'  ],
                ['class_hunter',  'class_shaman',     'class_priest', 'class_warlock'],
                ['class_paladin', 'class_deathknight'                                ]
            ),
            5 => array(
                ['faction_alliance', 'faction_horde']
            ),
            6 => array(
                [],
                [null, 'quest_start', 'quest_end', 'quest_start_daily', 'quest_end_daily']
            ),
            10 => array(                                    // really should have read holidays.dbc...
                'calendar_winterveilstart'            => 'calendar_winterveilstart',
                'calendar_noblegardenstart'           => 'calendar_noblegardenstart',
                'calendar_childrensweekstart'         => 'calendar_childrensweekstart',
                'calendar_fishingextravaganza'        => 'calendar_fishingextravaganzastart',
                'calendar_harvestfestivalstart'       => 'calendar_harvestfestivalstart',
                'calendar_hallowsendstart'            => 'calendar_hallowsendstart',
                'calendar_lunarfestivalstart'         => 'calendar_lunarfestivalstart',
                'calendar_loveintheairstart'          => 'calendar_loveintheairstart',
                'calendar_midsummerstart'             => 'calendar_midsummerstart',
                'calendar_brewfeststart'              => 'calendar_brewfeststart',
                'calendar_darkmoonfaireelwynnstart'   => 'calendar_darkmoonfaireelwynnstart',
                'calendar_darkmoonfairemulgorestart'  => 'calendar_darkmoonfairemulgorestart',
                'calendar_darkmoonfaireterokkarstart' => 'calendar_darkmoonfaireterokkarstart',
                'calendar_piratesday'                 => 'calendar_piratesdaystart',
                'calendar_wotlklaunch'                => 'calendar_wotlklaunchstart',
                'calendar_dayofthedeadstart'          => 'calendar_dayofthedeadstart',
                'calendar_fireworks'                  => 'calendar_fireworksstart'
            )
        );

        $writeImage = function($name, $ext, $src, $srcDims, $destDims, $done)
        {
            $ok   = false;
            $dest = imagecreatetruecolor($destDims['w'], $destDims['h']);
            imagesavealpha($dest, true);
            imagealphablending($dest, false);
            imagecopyresampled($dest, $src, $destDims['x'], $destDims['x'], $srcDims['x'], $srcDims['y'], $destDims['w'], $destDims['h'], $srcDims['w'], $srcDims['h']);

            switch ($ext)
            {
                case 'jpg':
                    $ok = imagejpeg($dest, $name.'.'.$ext, 85);
                    break;
                case 'gif':
                    $ok = imagegif($dest, $name.'.'.$ext);
                    break;
                case 'png':
                    $ok = imagepng($dest, $name.'.'.$ext);
                    break;
                default:
                    FileGen::status($done.' - unsupported file fromat: '.$ext, MSG_LVL_WARN);
            }

            imagedestroy($dest);

            if ($ok)
            {
                chmod($name.'.'.$ext, FileGen::$accessMask);
                FileGen::status($done.' - image '.$name.'.'.$ext.' written', MSG_LVL_OK);
            }
            else
                FileGen::status($done.' - could not create image '.$name.'.'.$ext, MSG_LVL_ERROR);

            return $ok;
        };

        $checkSourceDirs = function($sub, &$missing = []) use ($imgPath, $dbcPath, $paths)
        {
            foreach (array_column($paths, 0) as $subDir)
            {
                $p = sprintf($imgPath, $sub).$subDir;
                if (!FileGen::fileExists($p))
                    $missing[] = $p;
            }

            $p = sprintf($dbcPath, $sub);
            if (!FileGen::fileExists($p))
                $missing[] = $p;

            return !$missing;
        };

        if (isset(FileGen::$cliOpts['icons']))
            array_push($groups, 0, 2, 3, 4, 5, 10);
        if (isset(FileGen::$cliOpts['glyphs']))
            $groups[] = 1;
        if (isset(FileGen::$cliOpts['pagetexts']))
            array_push($groups, 7, 8, 9);
        if (isset(FileGen::$cliOpts['loadingscreens']))
            $groups[] = 11;

        // filter by pasaed options
        if (!$groups)                                       // by default do not generate loadingscreens
            unset($paths[11]);
        else
            foreach (array_keys($paths) as $k)
                if (!in_array($k, $groups))
                    unset($paths[$k]);

        foreach (FileGen::$localeIds as $l)
        {
            if ($checkSourceDirs(Util::$localeStrings[$l].'/'))
            {
                $locStr = Util::$localeStrings[$l].'/';
                break;
            }
        }

        // manually check for enGB
        if (!$locStr && $checkSourceDirs('enGB/'))
            $locStr = 'enGB/';

        // if no subdir had sufficient data, check mpq-root
        if (!$locStr && !$checkSourceDirs('', $missing))
        {
            FileGen::status('one or more required directories are missing:', MSG_LVL_ERROR);
            foreach ($missing as $m)
                FileGen::status(' - '.$m, MSG_LVL_ERROR);

            return;
        }

        // init directories
        foreach (array_column($paths, 1) as $subDirs)
            foreach ($subDirs as $sd)
                if (!FileGen::writeDir($destDir.$sd[0]))
                    $success = false;

        // ok, departure from std::procedure here
        // scan ItemDisplayInfo.dbc and SpellIcon.dbc for expected images and save them to an array
        // load all icon paths into another array and xor these two
        // excess entries for the directory are fine, excess entries for the dbc's are not
        $dbcEntries = [];

        if (isset($paths[0]) || isset($paths[1]))           // generates icons or glyphs
        {
            $spellIcon = new DBC('SpellIcon');
            if (isset($paths[0]) && !isset($paths[1]))
                $siRows = $spellIcon->readFiltered(function(&$val) { return !stripos($val['iconPath'], 'glyph-rune'); });
            else if (!isset($paths[0]) && isset($paths[1]))
                $siRows = $spellIcon->readFiltered(function(&$val) { return  stripos($val['iconPath'], 'glyph-rune'); });
            else
                $siRows = $spellIcon->readArbitrary();

            foreach ($siRows as $row)
                $dbcEntries[] = sprintf('setup/mpqdata/%s', $locStr).strtr($row['iconPath'], ['\\' => '/']).'.blp';
        }

        if (isset($paths[0]))
        {
            $itemDisplayInfo = new DBC('ItemDisplayInfo');
            foreach ($itemDisplayInfo->readArbitrary() as $row)
                $dbcEntries[] = sprintf($imgPath, $locStr).'Icons/'.$row['inventoryIcon1'].'.blp';

            $holidays = new DBC('Holidays');
            $holiRows = $holidays->readFiltered(function(&$val) { return !empty($val['textureString']); });
            foreach ($holiRows as $row)
                $dbcEntries[] = sprintf($imgPath, $locStr).'Calendar/Holidays/'.$row['textureString'].'Start.blp';
        }

        // case-insensitive array_unique *vomits silently into a corner*
        $dbcEntries = array_intersect_key($dbcEntries, array_unique(array_map('strtolower',$dbcEntries)));

        $allPaths = [];
        foreach ($paths as $i => $p)
        {
            $path = sprintf($imgPath, $locStr).$p[0];
            if (!FileGen::fileExists($path))
                continue;

            $files    = glob($path.$p[2], GLOB_BRACE);
            $allPaths = array_merge($allPaths, $files);

            FileGen::status('processing '.count($files).' files in '.$path.'...');

            $j = 0;
            foreach ($files as $f)
            {
                ini_set('max_execution_time', 30);          // max 30sec per image (loading takes the most time)

                $src   = null;
                $img   = explode('.', array_pop(explode('/', $f)));
                array_pop($img);                            // there are a hand full of images with multiple file endings or random dots in the name
                $img   = implode('.', $img);

                // file not from dbc -> name from array or skip file
                if (!empty($cuNames[$i]))
                {
                    if (!empty($cuNames[$i][strtolower($img)]))
                        $img = $cuNames[$i][strtolower($img)];
                    else if (!$p[4])
                    {
                        $j += count($p[1]);
                        FileGen::status('skipping extraneous file '.$img.' (+'.count($p[1]).')');
                        continue;
                    }
                }

                $nFiles = count($p[1]) * ($p[4] ? array_sum(array_map('count', $cuNames[$i])) : count($files));

                foreach ($p[1] as $info)
                {
                    if ($p[4])
                    {
                        foreach ($cuNames[$i] as $y => $row)
                        {
                            foreach ($row as $x => $name)
                            {
                                $j++;
                                $img  = $p[3] ? strtolower($name) : $name;
                                $done = ' - '.str_pad($j.'/'.$nFiles, 12).str_pad('('.number_format($j * 100 / $nFiles, 2).'%)', 9);

                                if (!isset(FileGen::$cliOpts['force']) && file_exists($destDir.$info[0].$img.'.'.$info[1]))
                                {
                                    FileGen::status($done.' - file '.$info[0].$img.'.'.$info[1].' was already processed');
                                    continue;
                                }

                                if (!$src)
                                    $src = imagecreatefromblp($f);

                                if (!$src)                              // error should be created by imagecreatefromblp
                                    continue;

                                $from = array(
                                    'x' => $info[4] + $p[4] * $x,
                                    'y' => $info[4] + $p[4] * $y,
                                    'w' => $p[4] - $info[4] * 2,
                                    'h' => $p[4] - $info[4] * 2
                                );
                                $to   = array(
                                    'x' => 0,
                                    'y' => 0,
                                    'w' => $info[3],
                                    'h' => $info[3]
                                );

                                if (!$writeImage($destDir.$info[0].$img, $info[1], $src, $from, $to, $done))
                                   $success = false;
                            }
                        }

                        // custom handle for combined icon 'quest_startend'
                        /* not used due to alphaChannel issues
                        if ($p[4] == 32)
                        {
                            $dest = imagecreatetruecolor(19, 16);
                            imagesavealpha($dest, true);
                            imagealphablending($dest, true);

                            // excalmationmark, questionmark
                            imagecopyresampled($dest, $src, 0, 1, 32 + 5, 32 + 2,  8, 15, 18, 30);
                            imagecopyresampled($dest, $src, 5, 0, 64 + 1, 32 + 1, 10, 16, 18, 28);

                            if (imagegif($dest, $destDir.$info[0].'quest_startend.gif'))
                                FileGen::status('                extra - image '.$destDir.$info[0].'quest_startend.gif written', MSG_LVL_OK);
                            else
                            {
                                FileGen::status('                extra - could not create image '.$destDir.$info[0].'quest_startend.gif', MSG_LVL_ERROR);
                                $success = false;
                            }

                            imagedestroy($dest);
                        }
                        */
                    }
                    else
                    {
                        // icon -> lowercase
                        if ($p[3])
                            $img = strtolower($img);

                        $j++;
                        $done = ' - '.str_pad($j.'/'.$nFiles, 12).str_pad('('.number_format($j * 100 / $nFiles, 2).'%)', 9);

                        if (!isset(FileGen::$cliOpts['force']) && file_exists($destDir.$info[0].$img.'.'.$info[1]))
                        {
                            FileGen::status($done.' - file '.$info[0].$img.'.'.$info[1].' was already processed');
                            continue;
                        }

                        if (!$src)
                            $src = imagecreatefromblp($f);

                        if (!$src)                              // error should be created by imagecreatefromblp
                            continue;

                        $from = array(
                            'x' => $info[4],
                            'y' => $info[4],
                            'w' => ($info[2] ?: imagesx($src)) - $info[4] * 2,
                            'h' => ($info[2] ?: imagesy($src)) - $info[4] * 2
                        );
                        $to   = array(
                            'x' => 0,
                            'y' => 0,
                            'w' => $info[3] ?: imagesx($src),
                            'h' => $info[3] ?: imagesy($src)
                        );

                        if (!$writeImage($destDir.$info[0].$img, $info[1], $src, $from, $to, $done))
                            $success = false;
                    }
                }

                unset($src);
            }
        }

        // reset execTime
        ini_set('max_execution_time', FileGen::$defaultExecTime);

        if ($missing = array_diff(array_map('strtolower', $dbcEntries), array_map('strtolower', $allPaths)))
        {
            asort($missing);
            FileGen::status('the following '.count($missing).' images where referenced by DBC but not in the mpqData directory. They may need to be converted by hand later on.', MSG_LVL_WARN);
            foreach ($missing as $m)
                FileGen::status(' - '.$m);
        }

        return $success;
    }
