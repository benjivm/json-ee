<?php

if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}

class Json
{
    /* settings */
    protected $content_type = 'application/json';
    protected $terminate = false;
    protected $xhr = false;
    protected $fields = [];
    protected $date_format = false;
    protected $jsonp = false;
    protected $callback;

    /* caches */
    public $entries;
    public $entries_entry_ids;
    public $entries_custom_fields;
    protected $entries_matrix_rows;
    protected $entries_matrix_cols;
    protected $entries_grid_rows;
    protected $entries_grid_cols;
    protected $entries_rel_data;
    protected $entries_relationship_data;
    protected $entries_grid_relationship_data;
    protected $entries_playa_data;
    protected $entries_channel_files_data;
    protected $image_manipulations = [];

    /**
     * @param null $entry_ids
     *
     * @return array|false|mixed|string
     */
    public function entries($entry_ids = null)
    {
        $this->initialize('entries');

        //exit if ajax request is required and not found
        if ($this->check_xhr_required()) {
            return '';
        }

        //instantiate channel module object
        if (empty($this->channel)) {
            require_once PATH_MOD . 'channel/mod.channel.php';

            $this->channel = new Channel();
        }

        $this->channel->initialize();

        $order_by_string = '';

        if (is_array($entry_ids)) {
            $this->entries_entry_ids = $entry_ids;
            $order_by_string = 'FIELD(t.entry_id,' . implode(',', $entry_ids) . ')';
        } else {
            //run through the channel module process to grab the entries
            $this->channel->uri = ($this->channel->query_string != '') ? $this->channel->query_string : 'index.php';

            if ($this->channel->enable['custom_fields'] === true) {
                $this->channel->fetch_custom_channel_fields();
            }

            $save_cache = false;

            if (ee()->config->item('enable_sql_caching') == 'y') {
                if (($this->channel->sql = $this->channel->fetch_cache()) == false) {
                    $save_cache = true;
                } else {
                    if (ee()->TMPL->fetch_param('dynamic') != 'no') {
                        if (preg_match("#(^|\/)C(\d+)#", $this->channel->query_string, $match) or in_array($this->channel->reserved_cat_segment, explode('/', $this->channel->query_string))) {
                            $this->channel->cat_request = true;
                        }
                    }
                }
            }

            if (!$this->channel->sql) {
                $this->channel->build_sql_query();
            }

            if (preg_match('/t\.entry_id IN \(([\d,]+)\)/', $this->channel->sql, $match)) {
                $this->entries_entry_ids = explode(',', $match[1]);
            }

            if (preg_match('/ORDER BY (.*)?/', $this->channel->sql, $match)) {
                $order_by_string = $match[1];
            }
        }

        if ($this->entries_entry_ids) {
            $this->entries_custom_fields = ee()->db->select('channel_fields.*, channels.channel_id')
                ->from('channel_fields')
                ->join('channel_field_groups_fields', 'channel_fields.field_id = channel_field_groups_fields.field_id')
                ->join('channels_channel_field_groups', 'channel_field_groups_fields.group_id = channel_field_groups_fields.group_id')
                ->join('channels', 'channels_channel_field_groups.group_id = channels.channel_id')
                ->where('channels.site_id', ee()->config->item('site_id'))
                ->where_in('channels.channel_name', explode('|', ee()->TMPL->fetch_param('channel')))
                ->get()
                ->result_array();

            $default_fields = [
                't.title',
                't.url_title',
                't.entry_id',
                't.channel_id',
                't.author_id',
                't.status',
                't.entry_date',
                't.edit_date',
                't.expiration_date',
            ];

            $select = [];

            if (!empty($this->fields)) {
                foreach ($default_fields as $field) {
                    $key = substr($field, 2);

                    if (in_array($key, $this->fields)) {
                        $select[] = $field;
                    }
                }
            } else {
                $select = $default_fields;
            }

            foreach ($this->entries_custom_fields as &$field) {
                if (empty($this->fields) || in_array($field['field_name'], $this->fields)) {
                    $select[] = 'wd.' . ee()->db->protect_identifiers('field_id_' . $field['field_id']) . ' AS ' . ee()->db->protect_identifiers($field['field_name']);
                }
            }

            //we need entry_id, always grab it
            if (!in_array('t.entry_id', $select)) {
                $select[] = 't.entry_id';
            }

            ee()->db->select(implode(', ', $select), false)
                ->from('channel_titles t')
                ->join('channel_data wd', 't.entry_id = wd.entry_id')
                ->where_in('t.entry_id', $this->entries_entry_ids);

            if ($order_by_string) {
                if (strpos($order_by_string, 'w.') !== false) {
                    ee()->db->join('channels w', 't.channel_id = w.channel_id');
                }

                if (strpos($order_by_string, 'm.') !== false) {
                    ee()->db->join('members m', 'm.member_id = t.author_id');
                }

                if (strpos($order_by_string, 'md.') !== false) {
                    ee()->db->join('member_data md', 'm.member_id = md.member_id');
                }

                if (ee()->TMPL->fetch_param('display_by') === 'week' && strpos($order_by_string, 'yearweek') !== false) {
                    $yearweek = true;

                    $offset = ee()->localize->zones[ee()->config->item('server_timezone')] * 3600;

                    $format = (ee()->TMPL->fetch_param('start_day') === 'Monday') ? '%x%v' : '%X%V';

                    ee()->db->select("DATE_FORMAT(FROM_UNIXTIME(entry_date + $offset), '$format') AS yearweek", false);
                }

                ee()->db->order_by($order_by_string, '', false);
            }

            $query = $this->channel->query = ee()->db->get();

            $show_categories = ee()->TMPL->fetch_param('show_categories') === 'yes';

            if ($show_categories) {
                $this->channel->fetch_categories();

                if (ee()->TMPL->fetch_param('show_category_group')) {
                    $show_category_group = explode('|', ee()->TMPL->fetch_param('show_category_group'));
                }
            }

            $this->entries = $query->result_array();

            $query->free_result();

            foreach ($this->entries as &$entry) {
                if (isset($yearweek)) {
                    unset($entry['yearweek']);
                }

                //format dates as javascript unix time (in microseconds!)
                if (isset($entry['entry_date'])) {
                    $entry['entry_date'] = $this->date_format($entry['entry_date']);
                }

                if (isset($entry['edit_date'])) {
                    $entry['edit_date'] = $this->date_format(strtotime($entry['edit_date']));
                }

                if (isset($entry['expiration_date'])) {
                    $entry['expiration_date'] = $this->date_format($entry['expiration_date']);
                }

                foreach ($this->entries_custom_fields as &$field) {
                    //call our custom callback for this fieldtype if it exists
                    if (isset($entry[$field['field_name']]) && is_callable([$this, 'entries_' . $field['field_type']])) {
                        $entry[$field['field_name']] = call_user_func([$this, 'entries_' . $field['field_type']], $entry['entry_id'], $field, $entry[$field['field_name']], $entry);
                    }
                }

                if ($show_categories) {
                    $entry['categories'] = [];

                    if (isset($this->channel->categories[$entry['entry_id']])) {
                        foreach ($this->channel->categories[$entry['entry_id']] as $raw_category) {
                            if (!empty($show_category_group) && !in_array($raw_category[5], $show_category_group)) {
                                continue;
                            }

                            $category = [
                                'category_id' => (int)$raw_category[0],
                                'parent_id' => (int)$raw_category[1],
                                'category_name' => $raw_category[2],
                                'category_image' => $raw_category[3],
                                'category_description' => $raw_category[4],
                                'category_group' => $raw_category[5],
                                'category_url_title' => $raw_category[6],
                            ];

                            foreach ($this->channel->catfields as $cat_field) {
                                $category[$cat_field['field_name']] = (isset($raw_category['field_id_' . $cat_field['field_id']])) ? $raw_category['field_id_' . $cat_field['field_id']] : '';
                            }

                            $entry['categories'][] = $category;
                        }
                    }
                }

                $entry['entry_id'] = (int)$entry['entry_id'];

                if (isset($entry['channel_id'])) {
                    $entry['channel_id'] = (int)$entry['channel_id'];
                }

                if (isset($entry['author_id'])) {
                    $entry['author_id'] = (int)$entry['author_id'];
                }
            }
        }

        ee()->load->library('javascript');

        ee()->load->library('typography');

        return $this->respond($this->entries, [ee()->typography, 'parse_file_paths']);
    }

    /**
     * @param $entry_id
     * @param $field
     * @param $field_data
     *
     * @return array
     */
    protected function entries_matrix($entry_id, $field, $field_data)
    {
        if (is_null($this->entries_matrix_rows)) {
            $query = ee()->db->where_in('entry_id', $this->entries_entry_ids)
                ->order_by('row_order')
                ->get('matrix_data');

            foreach ($query->result_array() as $row) {
                if (!isset($this->entries_matrix_rows[$row['entry_id']])) {
                    $this->entries_matrix_rows[$row['entry_id']] = [];
                }

                if (!isset($this->entries_matrix_rows[$row['entry_id']][$row['field_id']])) {
                    $this->entries_matrix_rows[$row['entry_id']][$row['field_id']] = [];
                }

                $this->entries_matrix_rows[$row['entry_id']][$row['field_id']][] = $row;
            }

            $query->free_result();
        }

        if (is_null($this->entries_matrix_cols)) {
            $query = ee()->db->get('matrix_cols');

            foreach ($query->result_array() as $row) {
                $this->entries_matrix_cols[$row['col_id']] = $row;
            }

            $query->free_result();
        }

        $data = [];

        if (isset($this->entries_matrix_rows[$entry_id][$field['field_id']])) {
            $field_settings = unserialize(base64_decode($field['field_settings']));

            foreach ($this->entries_matrix_rows[$entry_id][$field['field_id']] as $matrix_row) {
                $row = ['row_id' => (int)$matrix_row['row_id']];

                foreach ($field_settings['col_ids'] as $col_id) {
                    if (isset($this->entries_matrix_cols[$col_id])) {
                        $row[$this->entries_matrix_cols[$col_id]['col_name']] = $matrix_row['col_id_' . $col_id];
                    }
                }

                $data[] = $row;
            }
        }

        return $data;
    }

    /**
     * @param $entry_id
     * @param $field
     * @param $field_data
     *
     * @return array
     */
    protected function entries_grid($entry_id, $field, $field_data)
    {
        if (!isset($this->entries_grid_rows[$field['field_id']])) {
            $query = ee()->db->where_in('entry_id', $this->entries_entry_ids)
                ->order_by('row_order')
                ->get('channel_grid_field_' . $field['field_id']);

            foreach ($query->result_array() as $row) {
                if (!isset($this->entries_grid_rows[$field['field_id']][$row['entry_id']])) {
                    $this->entries_grid_rows[$field['field_id']][$row['entry_id']] = [];
                }

                $this->entries_grid_rows[$field['field_id']][$row['entry_id']][] = $row;
            }

            $query->free_result();
        }

        if (is_null($this->entries_grid_cols)) {
            $query = ee()->db->order_by('col_order', 'ASC')
                ->get('grid_columns');

            foreach ($query->result_array() as $row) {
                if (!isset($this->entries_grid_cols[$row['field_id']])) {
                    $this->entries_grid_cols[$row['field_id']] = [];
                }

                $this->entries_grid_cols[$row['field_id']][$row['col_id']] = $row;
            }

            $query->free_result();
        }

        $data = [];

        if (isset($this->entries_grid_rows[$field['field_id']][$entry_id]) && isset($this->entries_grid_cols[$field['field_id']])) {
            foreach ($this->entries_grid_rows[$field['field_id']][$entry_id] as $grid_row) {
                $row = ['row_id' => (int)$grid_row['row_id']];

                foreach ($this->entries_grid_cols[$field['field_id']] as $col_id => $col) {
                    $val = $grid_row['col_id_' . $col_id];

                    if ($col['col_type'] == 'relationship') {
                        $val = $this->entries_grid_relationship($col_id, $row['row_id'], $entry_id);
                    }

                    $row[$col['col_name']] = $val;
                }

                $data[] = $row;
            }
        }

        return $data;
    }

    /**
     * @param $entry_id
     * @param $field
     * @param $field_data
     *
     * @return mixed|void
     */
    protected function entries_rel($entry_id, $field, $field_data)
    {
        if (is_null($this->entries_rel_data)) {
            $query = ee()->db->select('rel_child_id, rel_id')
                ->where('rel_parent_id', $entry_id)
                ->get('relationships');

            $this->entries_rel_data = [];

            foreach ($query->result() as $row) {
                $this->entries_rel_data[$row->rel_id] = (int)$row->rel_child_id;
            }

            $query->free_result();
        }

        if (!isset($this->entries_rel_data[$field_data])) {
            return;
        }

        return $this->entries_rel_data[$field_data];
    }

    /**
     * @param $entry_id
     * @param $field
     * @param $field_data
     *
     * @return array
     */
    protected function entries_relationship($entry_id, $field, $field_data)
    {
        if (is_null($this->entries_relationship_data)) {
            $query = ee()->db->select('parent_id, child_id, field_id')
                ->where_in('parent_id', $this->entries_entry_ids)
                ->order_by('order', 'asc')
                ->get('relationships');

            foreach ($query->result_array() as $row) {
                if (!isset($this->entries_relationship_data[$row['parent_id']])) {
                    $this->entries_relationship_data[$row['parent_id']] = [];
                }

                if (!isset($this->entries_relationship_data[$row['parent_id']][$row['field_id']])) {
                    $this->entries_relationship_data[$row['parent_id']][$row['field_id']] = [];
                }

                $this->entries_relationship_data[$row['parent_id']][$row['field_id']][] = (int)$row['child_id'];
            }

            $query->free_result();
        }

        if (isset($this->entries_relationship_data[$entry_id][$field['field_id']])) {
            return $this->entries_relationship_data[$entry_id][$field['field_id']];
        }

        return [];
    }

    /**
     * @param $grid_col_id
     * @param $grid_row_id
     * @param $entry_id
     *
     * @return array
     */
    protected function entries_grid_relationship($grid_col_id, $grid_row_id, $entry_id)
    {
        if (is_null($this->entries_grid_relationship_data)) {
            $query = ee()->db->select('parent_id, child_id, grid_field_id, grid_col_id, grid_row_id')
                ->where_in('parent_id', $this->entries_entry_ids)
                ->where('grid_col_id', $grid_col_id)
                ->order_by('order', 'asc')
                ->get('relationships');

            foreach ($query->result_array() as $row) {
                if (!isset($this->entries_grid_relationship_data[$grid_col_id][$row['parent_id']][$row['grid_row_id']])) {
                    $this->entries_grid_relationship_data[$grid_col_id][$row['parent_id']][$row['grid_row_id']] = [];
                }
                if (!isset($this->entries_grid_relationship_data[$grid_col_id][$row['parent_id']][$row['grid_row_id']])) {
                    $this->entries_grid_relationship_data[$grid_col_id][$row['parent_id']][$row['grid_row_id']] = [];
                }
                $this->entries_grid_relationship_data[$grid_col_id][$row['parent_id']][$row['grid_row_id']][] = (int)$row['child_id'];
            }

            $query->free_result();
        }
        if (isset($this->entries_grid_relationship_data[$grid_col_id][$entry_id][$grid_row_id])) {
            return $this->entries_grid_relationship_data[$grid_col_id][$entry_id][$grid_row_id];
        }

        return [];
    }

    /**
     * @param $entry_id
     * @param $field
     * @param $field_data
     *
     * @return array
     */
    protected function entries_playa($entry_id, $field, $field_data)
    {
        if (is_null($this->entries_playa_data)) {
            $query = ee()->db->select('parent_entry_id, child_entry_id, parent_field_id')
                ->where_in('parent_entry_id', $this->entries_entry_ids)
                ->order_by('rel_order', 'asc')
                ->get('playa_relationships');

            foreach ($query->result_array() as $row) {
                if (!isset($this->entries_playa_data[$row['parent_entry_id']])) {
                    $this->entries_playa_data[$row['parent_entry_id']] = [];
                }

                if (!isset($this->entries_playa_data[$row['parent_entry_id']][$row['parent_field_id']])) {
                    $this->entries_playa_data[$row['parent_entry_id']][$row['parent_field_id']] = [];
                }

                $this->entries_playa_data[$row['parent_entry_id']][$row['parent_field_id']][] = (int)$row['child_entry_id'];
            }

            $query->free_result();
        }

        if (isset($this->entries_playa_data[$entry_id][$field['field_id']])) {
            return $this->entries_playa_data[$entry_id][$field['field_id']];
        }

        return [];
    }

    /**
     * @param $entry_id
     * @param $field
     * @param $field_data
     * @param $entry
     *
     * @return array|mixed
     */
    protected function entries_channel_files($entry_id, $field, $field_data, $entry)
    {
        $this->entries_channel_files_data = [];

        $field_settings = unserialize(base64_decode($field['field_settings']));
        $field_settings = $field_settings['channel_files'];

        $query = ee()->db->select()
            ->where('entry_id', $entry_id)
            ->where('field_id', $field['field_id'])
            ->order_by('file_order', 'asc')
            ->get('channel_files');

        foreach ($query->result_array() as $row) {
            $field_data = [
                'file_id' => (int)$row['file_id'],
                'url' => $row['filename'],
                'filename' => $row['filename'],
                'extension' => $row['extension'],
                'kind' => $row['mime'],
                'size' => $row['filesize'],
                'title' => $row['title'],
                'date' => $this->date_format($row['date']),
                'author' => (int)$row['member_id'],
                'desc' => $row['description'],
                'primary' => (bool)$row['file_primary'],
                'downloads' => (int)$row['downloads'],
                'custom1' => (isset($row['cffield1']) ? $row['cffield1'] : null),
                'custom2' => (isset($row['cffield2']) ? $row['cffield2'] : null),
                'custom3' => (isset($row['cffield3']) ? $row['cffield3'] : null),
                'custom4' => (isset($row['cffield4']) ? $row['cffield4'] : null),
                'custom5' => (isset($row['cffield5']) ? $row['cffield5'] : null),
            ];

            $fieldtype_specific_settings = $field_settings['locations'][$row['upload_service']];

            switch ($row['upload_service']) {
                case 'local':
                    // get upload folder details from EE
                    $query = ee()->db->select('url')
                        ->where('id', $fieldtype_specific_settings['location'])
                        ->get('exp_upload_prefs');

                    $result = $query->row_array();
                    $query->free_result();

                    $base_url = $result['url'] . ($field_settings['entry_id_folder'] == 'yes' ? $entry_id . '/' : '');
                    $field_data['url'] = $base_url . $field_data['url'];
                    break;
                case 's3':
                    if ($fieldtype_specific_settings['cloudfront_domain'] != '') {
                        $domain = rtrim($fieldtype_specific_settings['cloudfront_domain'], '/');
                        $domain = 'http://' . preg_replace('#https?://#', '', $domain);
                    } else {
                        $domain = "http://{$fieldtype_specific_settings['bucket']}.s3.amazonaws.com";
                    }

                    $dir = ($fieldtype_specific_settings['directory'] != '' ? rtrim($fieldtype_specific_settings['directory'], '/') . '/' : '');

                    $base_url = "{$domain}/{$dir}{$entry_id}/";
                    $field_data['url'] = $base_url . $field_data['url'];
                    break;
                case 'cloudfiles':
                case 'ftp':
                case 'sftp':
                    require_once PATH_THIRD . 'channel_files/locations/cfile_location.php';
                    require_once PATH_THIRD . "channel_files/locations/{$row['upload_service']}/{$row['upload_service']}.php";

                    $class_name = "CF_Location_{$row['upload_service']}";
                    $cf = new $class_name($fieldtype_specific_settings);
                    $dir = $entry_id;
                    $entry_id_folder = (isset($fieldtype_specific_settings['entry_id_folder']) ? $fieldtype_specific_settings['entry_id_folder'] : null);;
                    if (isset($entry_id_folder) && $fieldtype_specific_settings['entry_id_folder'] == 'no') {
                        $dir = false;
                    }

                    $field_data['url'] = $cf->parse_file_url($dir, $field_data['url']);
                    break;
                default:
                    break;
            }

            // make file size relevant
            $units = ['B', 'KB', 'MB', 'GB'];
            $units_index = 0;
            while ($field_data['size'] >= 1024) {
                $field_data['size'] /= 1024;
                $units_index++;
            }
            $field_data['size'] = round($field_data['size']) . ' ' . $units[$units_index];

            $this->entries_channel_files_data[$row['field_id']][] = $field_data;
        }

        $query->free_result();

        if (isset($row['field_id'], $this->entries_channel_files_data[$row['field_id']])) {
            return $this->entries_channel_files_data[$row['field_id']];
        }

        return [];
    }

    /**
     * @param $entry_id
     * @param $field
     * @param $field_data
     *
     * @return false|float|int|string|void
     */
    protected function entries_date($entry_id, $field, $field_data)
    {
        return $this->date_format($field_data);
    }

    /**
     * @param $entry_id
     * @param $field
     * @param $field_data
     *
     * @return float|int
     */
    protected function entries_text($entry_id, $field, $field_data)
    {
        $field_settings = ee()->api_channel_fields->get_settings($field['field_id']);

        if ($field_settings['field_content_type'] === 'numeric' || $field_settings['field_content_type'] === 'decimal') {
            return floatval($field_data);
        }

        if ($field_settings['field_content_type'] === 'integer') {
            return intval($field_data);
        }

        return $field_data;
    }

    /**
     * @param $entry_id
     * @param $field
     * @param $field_data
     * @param $entry
     * @param string $tagdata
     *
     * @return array
     */
    protected function entries_custom_field($entry_id, $field, $field_data, $entry, $tagdata = ' ')
    {
        ee()->load->add_package_path(ee()->api_channel_fields->ft_paths[$field['field_type']], false);

        ee()->api_channel_fields->setup_handler($field['field_id']);

        ee()->api_channel_fields->apply('_init', [
            [
                'row' => $entry,
                'content_id' => $entry['entry_id'],
                'content_type' => 'channel',
            ],
        ]);

        $field_data = ee()->api_channel_fields->apply('pre_process', [$field_data]);

        if (ee()->api_channel_fields->check_method_exists('replace_tag')) {
            require_once PATH_THIRD . 'json/libraries/Json_Template.php';

            $template = new Json_Template();

            $field_data = ee()->api_channel_fields->apply('replace_tag', [$field_data, [], $tagdata]);

            if ($template->variables) {
                $field_data = $template->variables;
            }

            unset($template);
        }

        ee()->load->remove_package_path(ee()->api_channel_fields->ft_paths[$field['field_type']]);

        return $field_data;
    }

    /**
     * @param $entry_id
     * @param $field
     * @param $field_data
     * @param $entry
     *
     * @return array
     */
    protected function entries_assets($entry_id, $field, $field_data, $entry)
    {
        $field_data = $this->entries_custom_field($entry_id, $field, $field_data, $entry);

        if (!is_array($field_data)) {
            $field_data = [];
        }

        if (isset($field_data['absolute_total_files']) && $field_data['absolute_total_files'] === 0) {
            return [];
        }

        $fields = [
            'file_id',
            'url',
            'subfolder',
            'filename',
            'extension',
            'date_modified',
            'kind',
            'width',
            'height',
            'size',
            'title',
            'date',
            'alt_text',
            'caption',
            'author',
            'desc',
            'location',
        ];

        foreach ($field_data as &$row) {
            $source_type = $row['source_type'];
            $filedir_id = $row['filedir_id'];
            //excise any other fields from this row
            $row = array_intersect_key($row, array_flip($fields));
            $row['file_id'] = (int)$row['file_id'];
            $row['date'] = $this->date_format($row['date']);
            $row['date_modified'] = $this->date_format($row['date_modified']);

            $row['manipulations'] = [];

            if ($source_type === 'ee') {
                if (!isset($this->image_manipulations[$filedir_id])) {
                    ee()->load->model('file_model');

                    $query = ee()->file_model->get_dimensions_by_dir_id($filedir_id);

                    $this->image_manipulations[$filedir_id] = $query->result();

                    $query->free_result();
                }

                foreach ($this->image_manipulations[$filedir_id] as $manipulation) {
                    $row['manipulations'][$manipulation->short_name] = pathinfo($row['url'], PATHINFO_DIRNAME) . '/_' . $manipulation->short_name . '/' . basename($row['url']);
                }
            }
        }

        return $field_data;
    }

    /**
     * @return array|false|mixed|string
     */
    public function search()
    {
        $search_id = ee()->TMPL->fetch_param('search_id');

        if (!$search_id) {
            $search_id = end(ee()->uri->segment_array());
        }

        if ($search_id) {
            $query = ee()->db->where('search_id', $search_id)
                ->limit(1)
                ->get('exp_search');

            if ($query->num_rows() > 0) {
                $search = $query->row_array();

                $query->free_result();

                if (preg_match('/IN \(([\d,]+)\)/', $query->row('query'), $match)) {
                    ee()->TMPL->tagparams['entry_id'] = (strpos($match[1], ',') !== false) ? str_replace(',', '|', $match[1]) : $match[1];

                    return $this->entries();
                }
            }
        }

        $this->initialize();

        return $this->respond([]);
    }

    /**
     * @return array|false|mixed|string
     */
    public function members()
    {
        $this->initialize();

        if ($this->check_xhr_required()) {
            return '';
        }

        $default_fields = [
            'm.member_id',
            'm.group_id',
            'm.username',
            'm.screen_name',
            'm.email',
            'm.signature',
            'm.avatar_filename',
            'm.avatar_width',
            'm.avatar_height',
            'm.photo_filename',
            'm.photo_width',
            'm.photo_height',
            'm.url',
            'm.location',
            'm.occupation',
            'm.interests',
            'm.bio',
            'm.join_date',
            'm.last_visit',
            'm.last_activity',
            'm.last_entry_date',
            'm.last_comment_date',
            'm.last_forum_post_date',
            'm.total_entries',
            'm.total_comments',
            'm.total_forum_topics',
            'm.total_forum_posts',
            'm.language',
            'm.timezone',
            'm.bday_d',
            'm.bday_m',
            'm.bday_y',
        ];

        if (version_compare(APP_VER, '2.6', '<')) {
            $default_fields[] = 'm.daylight_savings';
        }

        $query = ee()->db->select('m_field_id, m_field_name')
            ->get('member_fields');

        $custom_fields = $query->result_array();

        $query->free_result();

        $select = [];

        if (!empty($this->fields)) {
            foreach ($default_fields as $field) {
                $key = substr($field, 2);

                if (in_array($key, $this->fields)) {
                    $select[] = $field;
                }
            }
        } else {
            $select = $default_fields;
        }

        foreach ($custom_fields as &$field) {
            if (empty($this->fields) || in_array($field['m_field_name'], $this->fields)) {
                $select[] = 'd.' . ee()->db->protect_identifiers('m_field_id_' . $field['m_field_id']) . ' AS ' . ee()->db->protect_identifiers($field['m_field_name']);
            }
        }

        ee()->db->select(implode(', ', $select), false)
            ->from('members m')
            ->join('member_data d', 'm.member_id = d.member_id');

        if ($member_ids = ee()->TMPL->fetch_param('member_id')) {
            if ($member_ids === 'CURRENT_USER') {
                $member_ids = ee()->session->userdata('member_id');
            }

            ee()->db->where_in('m.member_id', explode('|', $member_ids));
        } elseif (ee()->TMPL->fetch_param('username')) {
            ee()->db->where_in('m.member_id', explode('|', ee()->TMPL->fetch_param('member_id')));
        }

        if (ee()->TMPL->fetch_param('group_id')) {
            ee()->db->where_in('m.group_id', explode('|', ee()->TMPL->fetch_param('group_id')));
        }

        if (ee()->TMPL->fetch_param('limit')) {
            ee()->db->limit(ee()->TMPL->fetch_param('limit'));
        }

        if (ee()->TMPL->fetch_param('offset')) {
            ee()->db->offset(ee()->TMPL->fetch_param('offset'));
        }

        $query = ee()->db->get();

        $members = $query->result_array();

        $query->free_result();

        $date_fields = [
            'join_date',
            'last_visit',
            'last_activity',
            'last_entry_date',
            'last_comment_date',
            'last_forum_post_date',
        ];

        foreach ($members as &$member) {
            foreach ($date_fields as $field) {
                if (isset($member[$field])) {
                    $member[$field] = $this->date_format($member[$field]);
                }
            }
        }

        return $this->respond($members);
    }

    /**
     * @param null $which
     */
    protected function initialize($which = null)
    {
        switch ($which) {
            case 'entries':
                //initialize caches
                $this->entries = [];
                $this->entries_entry_ids = [];
                $this->entries_custom_fields = [];
                $this->entries_matrix_rows = null;
                $this->entries_rel_data = null;
                $this->entries_relationship_data = null;
                $this->entries_grid_relationship_data = null;
                $this->entries_playa_data = null;
                $this->entries_channel_files_data = null;
                break;
        }

        $this->xhr = ee()->TMPL->fetch_param('xhr') === 'yes';

        $this->terminate = ee()->TMPL->fetch_param('terminate') === 'yes';

        $this->fields = (ee()->TMPL->fetch_param('fields')) ? explode('|', ee()->TMPL->fetch_param('fields')) : [];

        $this->date_format = ee()->TMPL->fetch_param('date_format');

        // get rid of EE formatted dates
        if ($this->date_format && strstr($this->date_format, '%')) {
            $this->date_format = str_replace('%', '', $this->date_format);
        }

        $this->jsonp = ee()->TMPL->fetch_param('jsonp') === 'yes';

        ee()->load->library('jsonp');

        $this->callback = (ee()->TMPL->fetch_param('callback') && ee()->jsonp->isValidCallback(ee()->TMPL->fetch_param('callback')))
            ? ee()->TMPL->fetch_param('callback') : null;

        $this->content_type = ee()->TMPL->fetch_param('content_type', ($this->jsonp && $this->callback) ? 'application/javascript' : 'application/json');
    }

    /**
     * @return bool
     */
    protected function check_xhr_required()
    {
        return $this->xhr && !ee()->input->is_ajax_request();
    }

    /**
     * @param $date
     *
     * @return false|float|int|string|void
     */
    protected function date_format($date)
    {
        if (!$date) {
            return;
        }

        return ($this->date_format) ? date($this->date_format, $date) : $date * 1000;
    }

    /**
     * @param array $response
     * @param null $callback
     *
     * @return array|false|mixed|string
     */
    protected function respond(array $response, $callback = null)
    {
        ee()->load->library('javascript');

        if ($item_root_node = ee()->TMPL->fetch_param('item_root_node')) {
            $response_with_nodes = [];

            foreach ($response as $item) {
                $response_with_nodes[] = [$item_root_node => $item];
            }

            $response = $response_with_nodes;
        }

        if ($root_node = ee()->TMPL->fetch_param('root_node')) {
            $response = [$root_node => $response];
        }

        $response = function_exists('json_encode')
            ? json_encode($response)
            : ee()->javascript->generate_json($response, true);

        if (!is_null($callback)) {
            $response = call_user_func($callback, $response);
        }

        if ($this->check_xhr_required()) {
            $response = '';
        } elseif ($this->jsonp && $this->callback) {
            $response = sprintf('%s(%s)', $this->callback, $response);
        }

        if ($this->terminate) {
            @header('Content-Type: ' . $this->content_type);

            exit($response);
        }

        return $response;
    }
}
