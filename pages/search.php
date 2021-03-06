<?php

if (!defined('AOWOW_REVISION'))
    die('invalid access');


/*
    if &json
        => search by compare or profiler
    else if &opensearch
        => suggestions when typing into searchboxes
        array:[
            str,        // search
            str[10],    // found
            [],         // unused
            [],         // unused
            [],         // unused
            [],         // unused
            [],         // unused
            str[10][4]  // type, typeId, param1 (4:quality, 3,6,9,10,17:icon, 5,11:faction), param2 (3:quality, 6:rank)
        ]
    else
        => listviews

todo    26: Listview - template: 'profile',     id: 'characters',    name: LANG.tab_characters,          visibleCols: ['race','classs','level','talents','gearscore','achievementpoints'],
        27: Profiles..?
        28: Guilds..?
        29: Arena Teams..?
*/


// tabId 0: Database g_initHeader()
class SearchPage extends GenericPage
{
    protected $tpl           = 'search';
    protected $tabId         = 0;
    protected $mode          = CACHE_TYPE_SEARCH;
    protected $js            = ['swfobject.js'];
    protected $lvTabs        = [];
    protected $search        = '';                          // output
    protected $invalid       = [];

    private   $statWeight    = ['wt' => null, 'wtv' => null];
    private   $maxResults    = CFG_SQL_LIMIT_SEARCH;
    private   $searchMask    = 0x0;
    private   $query         = '';                          // lookup
    private   $included      = [];
    private   $excluded      = [];
    private   $searches      = array(
        ['_searchCharClass'],   ['_searchCharRace'],    ['_searchTitle'],     ['_searchWorldEvent'],      ['_searchCurrency'],
        ['_searchItemset'],     ['_searchItem'],        ['_searchAbility'],   ['_searchTalent'],          ['_searchGlyph'],
        ['_searchProficiency'], ['_searchProfession'],  ['_searchCompanion'], ['_searchMount'],           ['_searchCreature'],
        ['_searchQuest'],       ['_searchAchievement'], ['_searchStatistic'], ['_searchZone'],            ['_searchObject'],
        ['_searchFaction'],     ['_searchSkill'],       ['_searchPet'],       ['_searchCreatureAbility'], ['_searchSpell'],
    );

    public function __construct($pageCall, $pageParam)
    {
        $this->search = trim(urlDecode($pageParam));
        $this->query  = strtr($this->search, '?*', '_%');

        // restricted access
        if ($this->reqUGroup && !User::isInGroup($this->reqUGroup))
            $this->error();

        // statWeight for JSON-search
        if (isset($_GET['wt']) && isset($_GET['wtv']))
        {
            $this->statWeight = array(
                'wt'  => explode(':', $_GET['wt']),
                'wtv' => explode(':', $_GET['wtv'])
            );
        }

        // select search mode
        if (isset($_GET['json']))
        {
            if ($_ = intVal($this->search))                 // allow for search by Id
                $this->query = $_;

            $type = isset($_GET['type']) ? intVal($_GET['type']) : 0;

            if (!empty($_GET['slots']))
                $this->searchMask |= SEARCH_TYPE_JSON | 0x40;
            else if ($type == TYPE_ITEMSET)
                $this->searchMask |= SEARCH_TYPE_JSON | 0x60;
            else if ($type == TYPE_ITEM)
                $this->searchMask |= SEARCH_TYPE_JSON | 0x40;
        }
        else if (isset($_GET['opensearch']))
        {
            $this->maxResults = CFG_SQL_LIMIT_QUICKSEARCH;
            $this->searchMask |= SEARCH_TYPE_OPEN | SEARCH_MASK_OPEN;
        }
        else
            $this->searchMask |= SEARCH_TYPE_REGULAR | SEARCH_MASK_ALL;

        // handle maintenance status for js-cases
        if (CFG_MAINTENANCE && !User::isInGroup(U_GROUP_EMPLOYEE) && !($this->searchMask & SEARCH_TYPE_REGULAR))
            $this->notFound();

        // fill include, exclude and ignore
        $this->tokenizeQuery();

        // invalid conditions: not enough characters to search OR no types to search
        if ((!$this->included || !($this->searchMask & SEARCH_MASK_ALL)) && !CFG_MAINTENANCE && !(($this->searchMask & SEARCH_TYPE_JSON) && intVal($this->search)))
        {
            $this->mode = CACHE_TYPE_NONE;
            $this->notFound();
        }

        parent::__construct($pageCall, $pageParam);         // just to set g_user and g_locale
    }

    private function tokenizeQuery()
    {
        if (!$this->query)
            return;

        foreach (explode(' ', $this->query) as $p)
        {
            if (!$p)                                        // multiple spaces
                continue;
            else if ($p[0] == '-')
            {
                if (strlen($p) < 4)
                    $this->invalid[] = substr($p, 1);
                else
                    $this->excluded[] = substr($p, 1);
            }
            else if ($p !== '')
            {
                if (strlen($p) < 3)
                    $this->invalid[] = $p;
                else
                    $this->included[] = $p;
            }
        }
    }

    protected function generateCacheKey($withStaff = true)
    {
        $staff = intVal($withStaff && User::isInGroup(U_GROUP_EMPLOYEE));

        $key = [$this->mode, $this->searchMask, md5($this->query), $staff, User::$localeId];

        return implode('_', $key);
    }

    protected function postCache()
    {
        if (!empty($this->lvTabs[3]))                       // has world events
        {
            // update WorldEvents to date()
            foreach ($this->lvTabs[3]['data'] as &$d)
            {
                $updated = WorldEventList::updateDates($d['_date']);
                unset($d['_date']);
                $d['startDate'] = $updated['start'] ? date(Util::$dateFormatInternal, $updated['start']) : false;
                $d['endDate']   = $updated['end']   ? date(Util::$dateFormatInternal, $updated['end'])   : false;
                $d['rec']       = $updated['rec'];
            }
        }

        if ($this->searchMask & SEARCH_TYPE_REGULAR)
        {
            $foundTotal = 0;
            foreach ($this->lvTabs as $_)
                $foundTotal += count($_['data']);

            if ($foundTotal == 1)                           // only one match -> redirect to find
            {
                $_      = array_pop($this->lvTabs);
                $type   = Util::$typeStrings[$_['type']];
                $typeId = key($_['data']);

                header('Location: ?'.$type.'='.$typeId, true, 302);
                exit();
            }
        }
    }

    protected function generateTitle()
    {
        array_unshift($this->title, $this->search, Lang::main('search'));
    }

    protected function generatePath() { }

    protected function generateContent()                    // just wrap it, so GenericPage can call and cache it
    {
        if ($this->mode == CACHE_TYPE_NONE)                 // search is invalid
            return;

        $this->performSearch();
    }

    public function notFound()
    {
        if ($this->searchMask & SEARCH_TYPE_REGULAR)
        {
            // empty queries go home
            if (!$this->query)
                header('Location: .', true, 302);

            parent::display();                              // errors are handled in the search-template itself
        }
        else if ($this->searchMask & SEARCH_TYPE_OPEN)
            $result = $this->generateOpenSearch(true);
        else /* if ($this->searchMask & SEARCH_TYPE_JSON) */
            $result = $this->generateJsonSearch(true);

        header("Content-type: application/x-javascript");
        exit($result);
    }

    public function display($override = '')
    {
        if ($override || ($this->searchMask & SEARCH_TYPE_REGULAR))
            return parent::display($override);
        else if ($this->searchMask & SEARCH_TYPE_OPEN)
        {
            if (!$this->loadCache($open))
            {
                $this->performSearch();
                $open = $this->generateOpenSearch();
                $this->saveCache($open);
            }
            header('Content-type: application/x-javascript; charset=utf-8');
            die($open);
        }
        else /* if ($this->searchMask & SEARCH_TYPE_JSON) */
        {
            if (!$this->loadCache($json))
            {
                $this->performSearch();
                $json = $this->generateJsonSearch();
                $this->saveCache($json);
            }
            header('Content-type: application/x-javascript; charset=utf-8');
            die($json);
        }
    }

    private function generateJsonSearch($asError = false)   // !note! dear reader, if you ever try to generate a string, that is to be evaled by JS, NEVER EVER terminate with a \n   .....   $totalHoursWasted +=2;
    {
        $outItems = '';
        $outSets  = '';

        if (!$asError)
        {
            // items
            if (!empty($this->lvTabs[6]['data']))
            {
                $items = [];
                foreach ($this->lvTabs[6]['data'] as $k => $v)
                    $items[] = Util::toJSON($v);

                $outItems = "\t".implode(",\n\t", $items)."\n";
            }

            // sets
            if (!empty($this->lvTabs[5]['data']))
            {
                $sets = [];
                foreach ($this->lvTabs[5]['data'] as $k => $v)
                {
                    unset($v['quality']);
                    if (!$v['heroic'])
                        unset($v['heroic']);

                    $sets[] = Util::toJSON($v);
                }

                $outSets = "\t".implode(",\n\t", $sets)."\n";
            }
        }

        return '["'.Util::jsEscape($this->search)."\", [\n".$outItems."],[\n".$outSets.']]';
    }

    private function generateOpenSearch($asError = false)
    {
        // this one is funny: we want 10 results, ideally equally distributed over each type
        $foundTotal = 0;
        $limit      = $this->maxResults;
        $result     = array(                                //idx1: names, idx3: resultUrl; idx7: extraInfo
            $this->search,
            [], [], [], [], [], [], []
        );

        foreach ($this->lvTabs as $_)
            $foundTotal += $_['matches'];

        if (!$foundTotal || $asError)
            return '["'.Util::jsEscape($this->search).'", []]';

        foreach ($this->lvTabs as $idx => $set)
        {
            $max = max(1, intVal($limit * $set['matches'] / $foundTotal));
            $limit -= $max;

            for ($i = 0; $i < $max; $i++)
            {
                $data = array_shift($set['data']);
                if (!$data)
                    break;

                $hasQ        = is_numeric($data['name'][0]) || $data['name'][0] == '@';
                $result[1][] = ($hasQ ? substr($data['name'], 1) : $data['name']).$set['appendix'];
                $result[3][] = HOST_URL.'/?'.Util::$typeStrings[$set['type']].'='.$data['id'];
                $extra       = [$set['type'], $data['id']];

                if (isset($data['param1']))
                    $extra[] = $data['param1'];

                if (isset($data['param2']))
                    $extra[] = $data['param2'];

                $result[7][] = $extra;
            }

            if ($limit <= 0)
                break;
        }

        return Util::toJSON($result);
    }

    private function createLookup(array $fields = [])
    {
        // default to name-field
        if (!$fields)
            $fields[] = 'name_loc'.User::$localeId;

        $qry = [];
        foreach ($fields as $f)
        {
            $sub = [];
            foreach ($this->included as $i)
                $sub[] = [$f, '%'.$i.'%'];

            foreach ($this->excluded as $x)
                $sub[] = [$f, '%'.$x.'%', '!'];

            // single cnd?
            if (count($sub) > 1)
                array_unshift($sub, 'AND');
            else
                $sub = $sub[0];

            $qry[] = $sub;
        }

        // single cnd?
        if (count($qry) > 1)
            array_unshift($qry, 'OR');
        else
            $qry = $qry[0];

        return $qry;
    }

    private function performSearch()
    {
        $cndBase = ['AND', $this->maxResults];

        // Exclude internal wow stuff
        if (!User::isInGroup(U_GROUP_EMPLOYEE))
            $cndBase[] = [['cuFlags', CUSTOM_EXCLUDE_FOR_LISTVIEW, '&'], 0];

        $shared = [];
        foreach ($this->searches as $idx => $ref)
            if ($this->searchMask & (1 << $idx))
                if ($_ = $this->$ref[0]($cndBase, $shared))
                    $this->lvTabs[$idx] = $_;
    }

    private function _searchCharClass($cndBase)             // 0 Classes: $searchMask & 0x00000001
    {
        $result  = [];
        $cnd     = array_merge($cndBase, [$this->createLookup()]);
        $classes = new CharClassList($cnd);

        if ($data = $classes->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($classes->iterate() as $__)
                    $data[$classes->id]['param1'] = 'class_'.strToLower($classes->getField('fileString'));

            $result = array(
                'type'     => TYPE_CLASS,
                'appendix' => ' (Class)',
                'matches'  => $classes->getMatches(),
                'file'     => CharClassList::$brickFile,
                'data'     => $data,
                'params'   => []
            );

            if ($classes->getMatches() > $this->maxResults)
            {
                // $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_', $classes->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }
        }

        return $result;
    }

    private function _searchCharRace($cndBase)              // 1 Races: $searchMask & 0x00000002
    {
        $result = [];
        $cnd    = array_merge($cndBase, [$this->createLookup()]);
        $races  = new CharRaceList($cnd);

        if ($data = $races->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($races->iterate() as $__)
                    $data[$races->id]['param1'] = '"race_'.strToLower($races->getField('fileString')).'_male"';

            $result = array(
                'type'     => TYPE_RACE,
                'appendix' => ' (Race)',
                'matches'  => $races->getMatches(),
                'file'     => CharRaceList::$brickFile,
                'data'     => $data,
                'params'   => []
            );

            if ($races->getMatches() > $this->maxResults)
            {
                // $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_', $races->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }
        }

        return $result;
    }

    private function _searchTitle($cndBase)                 // 2 Titles: $searchMask & 0x00000004
    {
        $result = [];
        $cnd    = array_merge($cndBase, [$this->createLookup(['male_loc'.User::$localeId, 'female_loc'.User::$localeId])]);
        $titles = new TitleList($cnd);

        if ($data = $titles->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($titles->iterate() as $id => $__)
                    $data[$id]['param1'] = $titles->getField('side');

            $result = array(
                'type'     => TYPE_TITLE,
                'appendix' => ' (Title)',
                'matches'  => $titles->getMatches(),
                'file'     => TitleList::$brickFile,
                'data'     => $data,
                'params'   => []
            );

            if ($titles->getMatches() > $this->maxResults)
            {
                // $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_', $titles->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }
        }

        return $result;
    }

    private function _searchWorldEvent($cndBase)            // 3 World Events: $searchMask & 0x00000008
    {
        $result  = [];
        $cnd     = array_merge($cndBase, array(
            array(
                'OR',
                $this->createLookup(['h.name_loc'.User::$localeId]),
                ['AND', $this->createLookup(['e.description']), ['e.holidayId', 0]]
            )
        ));
        $wEvents = new WorldEventList($cnd);

        if ($data = $wEvents->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($wEvents->getJSGlobals());

            // as allways: dates are updated in postCache-step

            $result = array(
                'type'     => TYPE_WORLDEVENT,
                'appendix' => ' (World Event)',
                'matches'  => $wEvents->getMatches(),
                'file'     => WorldEventList::$brickFile,
                'data'     => $data,
                'params'   => []
            );

            if ($wEvents->getMatches() > $this->maxResults)
            {
                // $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_', $wEvents->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }
        }

        return $result;
    }

    private function _searchCurrency($cndBase)              // 4 Currencies $searchMask & 0x0000010
    {
        $result = [];
        $cnd    = array_merge($cndBase, [$this->createLookup()]);
        $money  = new CurrencyList($cnd);

        if ($data = $money->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($money->iterate() as $__)
                    $data[$money->id]['param1'] = strToLower($money->getField('iconString'));

            $result = array(
                'type'     => TYPE_CURRENCY,
                'appendix' => ' (Currency)',
                'matches'  => $money->getMatches(),
                'file'     => CurrencyList::$brickFile,
                'data'     => $data,
                'params'   => []
            );

            if ($money->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_currenciesfound', $money->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }
        }

        return $result;
    }

    private function _searchItemset($cndBase, &$shared)     // 5 Itemsets $searchMask & 0x0000020
    {
        $result = [];
        $cnd    = array_merge($cndBase, [is_int($this->query) ? ['id', $this->query] : $this->createLookup()]);
        $sets   = new ItemsetList($cnd);

        if ($data = $sets->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($sets->getJSGlobals(GLOBALINFO_SELF));

            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($sets->iterate() as $__)
                    $data[$sets->id]['param1'] = $sets->getField('quality');

            $result = array(
                'type'     => TYPE_ITEMSET,
                'appendix' => ' (Item Set)',
                'matches'  => $sets->getMatches(),
                'file'     => ItemsetList::$brickFile,
                'data'     => $data,
                'params'   => [],
            );

            $shared['pcsToSet'] = $sets->pieceToSet;

            if ($sets->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_itemsetsfound', $sets->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?itemsets&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?itemsets&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchItem($cndBase, &$shared)        // 6 Items $searchMask & 0x0000040
    {
        $result   = [];
        $miscData = [];
        $cndAdd   = empty($this->query) ? [] : (is_int($this->query) ? ['id', $this->query] : $this->createLookup());

        if (($this->searchMask & SEARCH_TYPE_JSON) && ($this->searchMask & 0x20) && !empty($shared['pcsToSet']))
        {
            $cnd      = [['i.id', array_keys($shared['pcsToSet'])], CFG_SQL_LIMIT_NONE];
            $miscData = ['pcsToSet' => $shared['pcsToSet']];
        }
        else if (($this->searchMask & SEARCH_TYPE_JSON) && ($this->searchMask & 0x40))
        {
            $cnd   = $cndBase;
            $cnd[] = ['i.class', [ITEM_CLASS_WEAPON, ITEM_CLASS_GEM, ITEM_CLASS_ARMOR]];
            $cnd[] = $cndAdd;

            $slots = isset($_GET['slots']) ? explode(':', $_GET['slots']) : [];
            array_walk($slots, function(&$v, $k) { $v = intVal($v); });
            if ($_ = array_filter($slots))
                $cnd[] = ['slot', $_];

            $itemFilter = new ItemListFilter();
            if ($_ = $itemFilter->createConditionsForWeights($this->statWeight))
            {
                $miscData['extraOpts'] = $itemFilter->extraOpts;
                $cnd = array_merge($cnd, [$_]);
            }
        }
        else
            $cnd = array_merge($cndBase, [$cndAdd]);

        $items = new ItemList($cnd, $miscData);

        if ($data = $items->getListviewData($this->searchMask & SEARCH_TYPE_JSON ? (ITEMINFO_SUBITEMS | ITEMINFO_JSON) : 0))
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($items->getJSGlobals());

            if ($this->searchMask & SEARCH_TYPE_OPEN)
            {
                foreach ($items->iterate() as $__)
                {
                    $data[$items->id]['param1'] = $items->getField('iconString');
                    $data[$items->id]['param2'] = $items->getField('quality');
                }
            }

            $result = array(
                'type'     => TYPE_ITEM,
                'appendix' => ' (Item)',
                'matches'  => $items->getMatches(),
                'file'     => ItemList::$brickFile,
                'data'     => $data,
                'params'   => []
            );

            if ($items->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_itemsfound', $items->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?items&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?items&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchAbility($cndBase)               // 7 Abilities (Player + Pet) $searchMask & 0x0000080
    {
        $result = [];
        $cnd    = array_merge($cndBase, array(              // hmm, inclued classMounts..?
            ['s.typeCat', [7, -2, -3]],
            [['s.cuFlags', (SPELL_CU_TRIGGERED | SPELL_CU_TALENT), '&'], 0],
            [['s.attributes0', 0x80, '&'], 0],
            $this->createLookup()
        ));
        $abilities = new SpellList($cnd);

        if ($data = $abilities->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($abilities->getJSGlobals(GLOBALINFO_SELF | GLOBALINFO_RELATED));

            $vis = ['level', 'singleclass', 'schools'];
            if ($abilities->hasSetFields(['reagent1']))
                $vis[] = 'reagents';

            if ($this->searchMask & SEARCH_TYPE_OPEN)
            {
                foreach ($abilities->iterate() as $__)
                {
                    $data[$abilities->id]['param1'] = strToLower($abilities->getField('iconString'));
                    $data[$abilities->id]['param2'] = $abilities->ranks[$abilities->id];

                }
            }

            $result = array(
                'type'     => TYPE_SPELL,
                'appendix' => ' (Ability)',
                'matches'  => $abilities->getMatches(),
                'file'     => SpellList::$brickFile,
                'data'     => $data,
                'params'   => [
                    'id'          => 'abilities',
                    'name'        => '$LANG.tab_abilities',
                    'visibleCols' => '$'.Util::toJSON($vis)
                ]
            );

            if ($abilities->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_abilitiesfound', $abilities->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?spells=7&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?spells=7&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchTalent($cndBase)                // 8 Talents (Player + Pet) $searchMask & 0x0000100
    {
        $result = [];
        $cnd    = array_merge($cndBase, array(
            ['s.typeCat', [-7, -2]],
            $this->createLookup()
        ));
        $talents = new SpellList($cnd);

        if ($data = $talents->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($talents->getJSGlobals());

            $vis = ['level', 'singleclass', 'schools'];
            if ($talents->hasSetFields(['reagent1']))
                $vis[] = 'reagents';

            if ($this->searchMask & SEARCH_TYPE_OPEN)
            {
                foreach ($talents->iterate() as $__)
                {
                    $data[$talents->id]['param1'] = strToLower($talents->getField('iconString'));
                    $data[$talents->id]['param2'] = $talents->ranks[$talents->id];
                }
            }

            $result = array(
                'type'     => TYPE_SPELL,
                'appendix' => ' (Talent)',
                'matches'  => $talents->getMatches(),
                'file'     => SpellList::$brickFile,
                'data'     => $data,
                'params'   => [
                    'id'          => 'talents',
                    'name'        => '$LANG.tab_talents',
                    'visibleCols' => '$'.Util::toJSON($vis)
                ]
            );

            if ($talents->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_talentsfound', $talents->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?spells=-2&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?spells=-2&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchGlyph($cndBase)                 // 9 Glyphs $searchMask & 0x0000200
    {
        $result = [];
        $cnd    = array_merge($cndBase, array(
            ['s.typeCat', -13],
            $this->createLookup()
        ));
        $glyphs = new SpellList($cnd);

        if ($data = $glyphs->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($glyphs->getJSGlobals(GLOBALINFO_SELF));

            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($glyphs->iterate() as $__)
                    $data[$glyphs->id]['param1'] = strToLower($glyphs->getField('iconString'));

            $result = array(
                'type'     => TYPE_SPELL,
                'appendix' => ' (Glyph)',
                'matches'  => $glyphs->getMatches(),
                'file'     => SpellList::$brickFile,
                'data'     => $data,
                'params'   => [
                    'id'          => 'glyphs',
                    'name'        => '$LANG.tab_glyphs',
                    'visibleCols' => "$['singleclass', 'glyphtype']"
                ]
            );

            if ($glyphs->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_glyphsfound', $glyphs->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?spells=-13&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?spells=-13&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchProficiency($cndBase)           // 10 Proficiencies $searchMask & 0x0000400
    {
        $result = [];
        $cnd    = array_merge($cndBase, array(
            ['s.typeCat', -11],
            $this->createLookup()
        ));
        $prof = new SpellList($cnd);

        if ($data = $prof->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($prof->getJSGlobals(GLOBALINFO_SELF));

            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($prof->iterate() as $__)
                    $data[$prof->id]['param1'] = strToLower($prof->getField('iconString'));

            $result = array(
                'type'     => TYPE_SPELL,
                'appendix' => ' (Proficiency)',
                'matches'  => $prof->getMatches(),
                'file'     => SpellList::$brickFile,
                'data'     => $data,
                'params'   => [
                    'id'          => 'proficiencies',
                    'name'        => '$LANG.tab_proficiencies',
                    'visibleCols' => "$['classes']"
                ]
            );

            if ($prof->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_spellsfound', $prof->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?spells=-11&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?spells=-11&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchProfession($cndBase)            // 11 Professions (Primary + Secondary) $searchMask & 0x0000800
    {
        $result = [];
        $cnd    = array_merge($cndBase, array(
            ['s.typeCat', [9, 11]],
            $this->createLookup()
        ));
        $prof = new SpellList($cnd);

        if ($data = $prof->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($prof->getJSGlobals(GLOBALINFO_SELF | GLOBALINFO_RELATED));

            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($prof->iterate() as $__)
                    $data[$prof->id]['param1'] = strToLower($prof->getField('iconString'));

            $result = array(
                'type'     => TYPE_SPELL,
                'appendix' => ' (Profession)',
                'matches'  => $prof->getMatches(),
                'file'     => SpellList::$brickFile,
                'data'     => $data,
                'params'   => [
                    'id'          => 'professions',
                    'name'        => '$LANG.tab_professions',
                    'visibleCols' => "$['source', 'reagents']"
                ]
            );

            if ($prof->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_professionfound', $prof->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?spells=11&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?spells=11&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchCompanion($cndBase)             // 12 Companions $searchMask & 0x0001000
    {
        $result = [];
        $cnd    = array_merge($cndBase, array(
            ['s.typeCat', -6],
            $this->createLookup()
        ));
        $vPets = new SpellList($cnd);

        if ($data = $vPets->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($vPets->getJSGlobals());

            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($vPets->iterate() as $__)
                    $data[$vPets->id]['param1'] = strToLower($vPets->getField('iconString'));

            $result = array(
                'type'     => TYPE_SPELL,
                'appendix' => ' (Companion)',
                'matches'  => $vPets->getMatches(),
                'file'     => SpellList::$brickFile,
                'data'     => $data,
                'params'   => [
                    'id'          => 'companions',
                    'name'        => '$LANG.tab_companions',
                    'visibleCols' => "$['reagents']"
                ]
            );

            if ($vPets->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_companionsfound', $vPets->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?spells=-6&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?spells=-6&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchMount($cndBase)                 // 13 Mounts $searchMask & 0x0002000
    {
        $result = [];
        $cnd    = array_merge($cndBase, array(
            ['s.typeCat', -5],
            $this->createLookup()
        ));
        $mounts = new SpellList($cnd);

        if ($data = $mounts->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($mounts->getJSGlobals(GLOBALINFO_SELF));

            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($mounts->iterate() as $__)
                    $data[$mounts->id]['param1'] = strToLower($mounts->getField('iconString'));

            $result = array(
                'type'     => TYPE_SPELL,
                'appendix' => ' (Mount)',
                'matches'  => $mounts->getMatches(),
                'file'     => SpellList::$brickFile,
                'data'     => $data,
                'params'   => [
                    'id'   => 'mounts',
                    'name' => '$LANG.tab_mounts',
                ]
            );

            if ($mounts->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_mountsfound', $mounts->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?spells=-5&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?spells=-5&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchCreature($cndBase)              // 14 NPCs $searchMask & 0x0004000
    {
        $result = [];
        $cnd    = array_merge($cndBase, array(
            [['flagsExtra', 0x80], 0],                      // exclude trigger creatures
        //  [['cuFlags', MASK, '&'], 0],                    // todo (med): exclude difficulty entries
            $this->createLookup()
        ));
        $npcs = new CreatureList($cnd);

        if ($data = $npcs->getListviewData())
        {
            $result = array(
                'type'     => TYPE_NPC,
                'appendix' => ' (NPC)',
                'matches'  => $npcs->getMatches(),
                'file'     => CreatureList::$brickFile,
                'data'     => $data,
                'params'   => [
                    'id'   => 'npcs',
                    'name' => '$LANG.tab_npcs',
                ]
            );

            if ($npcs->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_npcsfound', $npcs->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?npcs&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?npcs&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchQuest($cndBase)                 // 15 Quests $searchMask & 0x0008000
    {
        $result = [];
        $cnd    = array_merge($cndBase, array(
        //  [['cuFlags', MASK, '&'], 0],                    // todo (med): identify disabled quests
            $this->createLookup()
        ));
        $quests = new QuestList($cnd);

        if ($data = $quests->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($quests->getJSGlobals());

            $result = array(
                'type'     => TYPE_QUEST,
                'appendix' => ' (Quest)',
                'matches'  => $quests->getMatches(),
                'file'     => QuestList::$brickFile,
                'data'     => $data,
                'params'   => []
            );

            if ($quests->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_questsfound', $quests->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?quests&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?quests&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchAchievement($cndBase)           // 16 Achievements $searchMask & 0x0010000
    {
        $result = [];
        $cnd    = array_merge($cndBase, array(
            [['flags', ACHIEVEMENT_FLAG_COUNTER, '&'], 0],  // not a statistic
            $this->createLookup()
        ));
        $acvs = new AchievementList($cnd);

        if ($data = $acvs->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($acvs->getJSGlobals());

            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($acvs->iterate() as $__)
                    $data[$acvs->id]['param1'] = strToLower($acvs->getField('iconString'));

            $result = array(
                'type'     => TYPE_ACHIEVEMENT,
                'appendix' => ' (Achievement)',
                'matches'  => $acvs->getMatches(),
                'file'     => AchievementList::$brickFile,
                'data'     => $data,
                'params'   => [
                    'visibleCols' => "$['category']"
                ]
            );

            if ($acvs->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_achievementsfound', $acvs->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?achieveemnts&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?achievements&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchStatistic($cndBase)             // 17 Statistics $searchMask & 0x0020000
    {
        $result = [];
        $cnd    = array_merge($cndBase, array(
            ['flags', ACHIEVEMENT_FLAG_COUNTER, '&'],       // is a statistic
            $this->createLookup()
        ));
        $stats = new AchievementList($cnd);

        if ($data = $stats->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($stats->getJSGlobals(GLOBALINFO_SELF));

            $result = array(
                'type'     => TYPE_ACHIEVEMENT,
                'matches'  => $stats->getMatches(),
                'file'     => AchievementList::$brickFile,
                'data'     => $data,
                'params'   => [
                    'visibleCols' => "$['category']",
                    'hiddenCols'  => "$['side', 'points', 'rewards']",
                    'name'        => '$LANG.tab_statistics',
                    'id'          => 'statistics'
                ]
            );

            if ($stats->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_statisticsfound', $stats->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?achievements=1&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?achievements=1&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchZone($cndBase)                  // 18 Zones $searchMask & 0x0040000
    {
        $result = [];
        $cnd    = array_merge($cndBase, [$this->createLookup()]);
        $zones  = new ZoneList($cnd);

        if ($data = $zones->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($zones->getJSGlobals());

            $result = array(
                'type'     => TYPE_ZONE,
                'appendix' => ' (Zone)',
                'matches'  => $zones->getMatches(),
                'file'     => ZoneList::$brickFile,
                'data'     => $data,
                'params'   => []
            );

            if ($zones->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_zonesfound', $zones->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }
        }

        return $result;
    }

    private function _searchObject($cndBase)                // 19 Objects $searchMask & 0x0080000
    {
        $result  = [];
        $cnd     = array_merge($cndBase, [$this->createLookup()]);
        $objects = new GameObjectList($cnd);

        if ($data = $objects->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($objects->getJSGlobals());

            $result = array(
                'type'     => TYPE_OBJECT,
                'appendix' => ' (Object)',
                'matches'  => $objects->getMatches(),
                'file'     => GameObjectList::$brickFile,
                'data'     => $data,
                'params'   => []
            );

            if ($objects->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_objectsfound', $objects->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?objects&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?objects&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchFaction($cndBase)               // 20 Factions $searchMask & 0x0100000
    {
        $result   = [];
        $cnd      = array_merge($cndBase, [$this->createLookup()]);
        $factions = new FactionList($cnd);

        if ($data = $factions->getListviewData())
        {
            $result = array(
                'type'     => TYPE_FACTION,
                'appendix' => ' (Faction)',
                'matches'  => $factions->getMatches(),
                'file'     => FactionList::$brickFile,
                'data'     => $data,
                'params'   => []
            );

            if ($factions->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_factionsfound', $factions->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }
        }

        return $result;
    }

    private function _searchSkill($cndBase)                 // 21 Skills $searchMask & 0x0200000
    {
        $result = [];
        $cnd    = array_merge($cndBase, [$this->createLookup()]);
        $skills = new SkillList($cnd);

        if ($data = $skills->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($skills->iterate() as $id => $__)
                    $data[$id]['param1'] = $skills->getField('iconString');

            $result = array(
                'type'     => TYPE_SKILL,
                'appendix' => ' (Skill)',
                'matches'  => $skills->getMatches(),
                'file'     => SkillList::$brickFile,
                'data'     => $data,
                'params'   => []
            );

            if ($skills->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_skillsfound', $skills->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }
        }

        return $result;
    }

    private function _searchPet($cndBase)                   // 22 Pets $searchMask & 0x0400000
    {
        $result = [];
        $cnd    = array_merge($cndBase, [$this->createLookup()]);
        $pets   = new PetList($cnd);

        if ($data = $pets->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($pets->iterate() as $__)
                    $data[$pets->id]['param1'] = $pets->getField('iconString');

            $result = array(
                'type'     => TYPE_PET,
                'appendix' => ' (Pet)',
                'matches'  => $pets->getMatches(),
                'file'     => PetList::$brickFile,
                'data'     => $data,
                'params'   => []
            );

            if ($pets->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_petsfound', $pets->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }
        }

        return $result;
    }

    private function _searchCreatureAbility($cndBase)       // 23 NPCAbilities $searchMask & 0x0800000
    {
        $result = [];
        $cnd    = array_merge($cndBase, array(
            ['s.typeCat', -8],
            $this->createLookup()
        ));
        $npcAbilities = new SpellList($cnd);

        if ($data = $npcAbilities->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($npcAbilities->getJSGlobals(GLOBALINFO_SELF));

            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($npcAbilities->iterate() as $__)
                    $data[$npcAbilities->id]['param1'] = strToLower($npcAbilities->getField('iconString'));

            $result = array(
                'type'     => TYPE_SPELL,
                'appendix' => ' (Spell)',
                'matches'  => $npcAbilities->getMatches(),
                'file'     => SpellList::$brickFile,
                'data'     => $data,
                'params'   => [
                    'id'          => 'npc-abilities',
                    'name'        => '$LANG.tab_npcabilities',
                    'visibleCols' => "$['level']",
                    'hiddenCols'  => "$['skill']"
                ]
            );

            if ($npcAbilities->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_spellsfound', $npcAbilities->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?spells=-8&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?spells=-8&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    private function _searchSpell($cndBase)                 // 24 Spells (Misc + GM) $searchMask & 0x1000000
    {
        $result = [];
        $cnd    = array_merge($cndBase, array(
            ['s.typeCat', [0, -9]],
            $this->createLookup()
        ));
        $misc = new SpellList($cnd);

        if ($data = $misc->getListviewData())
        {
            if ($this->searchMask & SEARCH_TYPE_REGULAR)
                $this->extendGlobalData($misc->getJSGlobals(GLOBALINFO_SELF | GLOBALINFO_RELATED));

            if ($this->searchMask & SEARCH_TYPE_OPEN)
                foreach ($misc->iterate() as $__)
                    $data[$misc->id]['param1'] = strToLower($misc->getField('iconString'));

            $result = array(
                'type'     => TYPE_SPELL,
                'appendix' => ' (Spell)',
                'matches'  => $misc->getMatches(),
                'file'     => SpellList::$brickFile,
                'data'     => $data,
                'params'   => [
                    'name'        => '$LANG.tab_uncategorizedspells',
                    'visibleCols' => "$['level']",
                    'hiddenCols'  => "$['skill']",
                ]
            );

            if ($misc->getMatches() > $this->maxResults)
            {
                $result['params']['note'] = sprintf(Util::$tryNarrowingString, 'LANG.lvnote_spellsfound', $misc->getMatches(), $this->maxResults);
                $result['params']['_truncated'] = 1;
            }

            if (isset($result['params']['note']))
                $result['params']['note'] .= ' + LANG.dash + $WH.sprintf(LANG.lvnote_filterresults, \'?spells=0&filter=na='.urlencode($this->search).'\')';
            else
                $result['params']['note'] = '$$WH.sprintf(LANG.lvnote_filterresults, \'?spells=0&filter=na='.urlencode($this->search).'\')';
        }

        return $result;
    }

    // private function _searchCharacter($cndBase) { }      // 25 Characters $searchMask & 0x2000000
    // private function _searchGuild($cndBase) { }          // 26 Guilds $searchMask & 0x4000000
    // private function _searchArenaTeam($cndBase) { }      // 27 Arena Teams $searchMask & 0x8000000
}

?>
