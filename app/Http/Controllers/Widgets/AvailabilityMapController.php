<?php
/**
 * AvailabilityMapController.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2018 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace App\Http\Controllers\Widgets;

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Service;
use Illuminate\Http\Request;
use LibreNMS\Config;

class AvailabilityMapController extends WidgetController
{
    protected $title = 'Availability Map';

    public function __construct()
    {
        $this->defaults = [
            'title' => null,
            'type' => (int) Config::get('webui.availability_map_compact', 0),
            'tile_size' => 12,
            'color_only_select' => 0,
            'show_disabled_and_ignored' => 0,
            'mode_select' => 0,
            'order_by' => Config::get('webui.availability_map_sort_status') ? 'status' : 'hostname',
            'device_group' => null,
        ];
    }

    public function getView(Request $request)
    {
        $data = $this->getSettings();

        $devices = [];
        $device_totals = [];
        $services = [];
        $services_totals = [];

        $mode = $data['mode_select'];
        if ($mode == 0 || $mode == 2) {
            [$devices, $device_totals] = $this->getDevices($request);
        }
        if ($mode > 0) {
            [$services, $services_totals] = $this->getServices($request);
        }

        $data['device'] = Device::first();

        $data['devices'] = $devices;
        $data['device_totals'] = $device_totals;
        $data['services'] = $services;
        $data['services_totals'] = $services_totals;

        return view('widgets.availability-map', $data);
    }

    public function getSettingsView(Request $request)
    {
        return view('widgets.settings.availability-map', $this->getSettings(true));
    }

    /**
     * @param  Request  $request
     * @return array
     */
    private function getDevices(Request $request)
    {
        $settings = $this->getSettings();

        // filter for by device group or show all
        if ($settings['device_group']) {
            $device_query = DeviceGroup::find($settings['device_group'])->devices()->hasAccess($request->user());
        } else {
            $device_query = Device::hasAccess($request->user());
        }

        if (! $settings['show_disabled_and_ignored']) {
            $device_query->isNotDisabled();
        }
        $device_query->orderBy($settings['order_by']);
        $devices = $device_query->select(['devices.device_id', 'hostname', 'sysName', 'display', 'status', 'uptime', 'last_polled', 'disabled', 'disable_notify', 'location_id'])->get();

        // process status
        $uptime_warn = Config::get('uptime_warning', 84600);
        $totals = ['warn' => 0, 'up' => 0, 'down' => 0, 'maintenance' => 0, 'ignored' => 0, 'disabled' => 0];
        $data = [];

        foreach ($devices as $device) {
            $row = ['device' => $device];
            if ($device->disabled) {
                $totals['disabled']++;
                $row['stateName'] = 'disabled';
                $row['labelClass'] = 'blackbg';
            } elseif ($device->disable_notify) {
                $totals['ignored']++;
                $row['stateName'] = 'alert-dis';
                $row['labelClass'] = 'label-default';
            } elseif ($device->status == 1) {
                if (($device->uptime < $uptime_warn) && ($device->uptime != 0)) {
                    $totals['warn']++;
                    $row['stateName'] = 'warn';
                    $row['labelClass'] = 'label-warning';
                } else {
                    $totals['up']++;
                    $row['stateName'] = 'up';
                    $row['labelClass'] = 'label-success';
                }
            } else {
                $totals['down']++;
                $row['stateName'] = 'down';
                $row['labelClass'] = 'label-danger';
            }

            if ($device->isUnderMaintenance()) {
                $row['labelClass'] = 'label-default';
                $totals['maintenance']++;
            }
            $data[] = $row;
        }

        return [$data, $totals];
    }

    private function getServices($request)
    {
        $settings = $this->getSettings();

        // filter for by device group or show all
        if ($settings['device_group']) {
            $services_query = DeviceGroup::find($settings['device_group'])->services()->hasAccess($request->user());
        } else {
            $services_query = Service::hasAccess($request->user());
        }

        if ($settings['order_by'] == 'status') {
            $services_query->orderBy('service_status', 'DESC')->orderBy('service_type');
        } elseif ($settings['order_by'] == 'hostname') {
            $services_query->leftJoin('devices', 'services.device_id', 'devices.device_id')->orderBy('hostname')->orderBy('service_type');
        }
        $services = $services_query->with(['device' => function ($query) {
            $query->select(['devices.device_id', 'hostname', 'sysName']);
        }])->select(['service_id', 'services.device_id', 'service_type', 'service_desc', 'service_status'])->get();

        // process status
        $totals = ['warn' => 0, 'up' => 0, 'down' => 0];
        $data = [];
        foreach ($services as $service) {
            $row = ['service' => $service];
            if ($service->service_status == 0) {
                $row['labelClass'] = 'label-success';
                $row['stateName'] = 'up';
                $totals['up']++;
            } elseif ($service->service_status == 1) {
                $row['labelClass'] = 'label-warning';
                $row['stateName'] = 'warn';
                $totals['warn']++;
            } else {
                $row['labelClass'] = 'label-danger';
                $row['stateName'] = 'down';
                $totals['down']++;
            }
            $data[] = $row;
        }

        return [$data, $totals];
    }
}
