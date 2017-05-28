<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use GuzzleHttp\Client;

use App\Modules\Themes\Models\Theme;

use Storage;
use Core;
use Zipper;
use Cache;
use Artisan;

class AdminUpdatesController extends Controller
{
		public function index($module_type = null)
		{
				return view('core::UpdatesAdmin/index');
		}

		public function browse($module_type = null)
		{
				$client = new Client();

				$res = $client->request('GET', $this->apiUrl . '/' . (!empty($module_type) ? $module_type : ''), [ 'http_errors' => false ]);

				if ($res->getStatusCode() == 200) {
					$modules = json_decode($res->getBody(), true);
					$modules = $modules['items'];
				} else {
					$modules = [];
				}

				// convert to obj
				// so we can easily update the
				// module box partials in one format
				$convertToObj = function($module) {
						$obj = json_decode(json_encode($module), FALSE);

						return $obj;
				};

				foreach($modules as $key => $module) {
						$modules[$key] = $convertToObj($module);
				}

				return view('core::UpdatesAdmin/browse', compact('modules', 'module_type'));
		}

		public function view($id)
		{
				$client = new Client();

				// get the theme
				$res = $client->request('GET', $this->apiUrl . '/module/' . $id, [ 'http_errors' => false ]);

				if ($res->getStatusCode() == 200) {
					$module = json_decode($res->getBody(), true);
				} else {
					abort(404);
				}

				return view('core::UpdatesAdmin/view', compact('module'));
		}

		public function installTheme($id)
		{
				$client = new Client();

				// get the theme
				$res = $client->request('GET', $this->apiUrl . '/module/' . $id, [ 'http_errors' => false ]);

				if ($res->getStatusCode() == 200) {
					$module = json_decode($res->getBody(), true);
				} else {
					abort(404);
				}

				// set slug
				$slug = ucfirst($module['slug']);

				// download the latest version
				$res = $client->request('GET', $module['latest_version']['download_url']);

				if ($res->getStatusCode() == 200) {
					$filename = $module['slug'] . '.zip';

					Storage::disk('themes')->put($filename, $res->getBody(), 'public');
				} else {
					abort(404);
				}

				// then attempt to extract contents
				$path = public_path() . '/themes/' . $filename;

				Zipper::make($path)->extractTo(public_path() . '/themes');

				// once we've gotten the files all setup
				// lets run the install event, if it exists
				$this->fireEvent($slug, $slug . 'Install');

				// save the theme
				$theme = new Theme;

				$theme->name = $module['name'];
				$theme->slug = $module['slug'];
				$theme->custom = 0;
				$theme->status = 1;
				$theme->user_id = Auth::user()->id;

				$theme->save();

				// we'll return to the themes index on success
				return redirect()->route('admin.themes.index')->with('success', $module['name'] . ' theme has been installed!');
		}

		public function installPlugin(Request $request, $id)
		{
				$client = new Client();

				// get the plugin
				$res = $client->request('GET', $this->apiUrl . '/module/' . $id, [ 'http_errors' => false ]);

				if ($res->getStatusCode() == 200) {
					$module = json_decode($res->getBody(), true);
				} else {
					abort(404);
				}

				// set slug
				$slug = ucfirst($module['slug']);

				// download the latest version
				$res = $client->request('GET', $module['latest_version']['download_url'], [ 'http_errors' => false ]);

				if ($res->getStatusCode() == 200) {
					$filename = $module['slug'] . '.zip';

					Storage::disk('plugins')->put($filename, (string) $res->getBody(), 'public');
				} else {
					abort(404);
				}

				// then attempt to extract contents
				$path = base_path() . '/app/Modules/' . $filename;

				Zipper::make($path)->extractTo(base_path() . '/app/Modules');

				// once we've gotten the files all setup
				// lets run the install event, if it exists
				$this->fireEvent($slug, $slug . 'Install');

				Plugin::enable($slug);

				// grab the modules json file
				$file = json_decode(Storage::disk('local')->get('modules.json'), true);

				// create the new entry
				$file[$slug] = [
					'basename' => $slug,
					'name' => $module['name'],
					'slug' => $module['slug'],
					'version' => $module['latest_version']['version'],
					'description' => $module['description'],
					'id' => $module['id'],
					'enabled' => true,
					'order' => 9001
				];

				// write to module.json
				Storage::disk('plugins')->put($slug . '/module.json', json_encode($file[$slug]), 'public');

				// save the global modules.json
				Storage::disk('local')->put('modules.json', json_encode($file), 'public');

				// we'll return to the plugins index on success
				return redirect()->route('admin.plugins.index')->with('success', $module['name'] . ' plugin has been installed!');
		}

		public function updateTheme($id)
		{
				$client = new Client();

				// get the theme
				$res = $client->request('GET', $this->apiUrl . '/module/' . $id, [ 'http_errors' => false ]);

				if ($res->getStatusCode() == 200) {
					$module = json_decode($res->getBody(), true);
				} else {
					abort(404);
				}

				// set slug
				$slug = ucfirst($module['slug']);

				// download the latest version
				$res = $client->request('GET', $module['latest_version']['download_url']);

				if ($res->getStatusCode() == 200) {
					$filename = $module['slug'] . '.zip';

					Storage::disk('themes')->put($filename, $res->getBody(), 'public');
				} else {
					abort(404);
				}

				// then attempt to extract contents
				$path = public_path() . '/themes/' . $filename;

				Zipper::make($path)->extractTo(public_path() . '/themes');

				// once we've gotten the files all setup
				// lets run the upgrade event with the version #, if it exists
				$this->fireEvent($slug, $slug . 'Update', $module['latest_version']['version']);

				// we'll return to the themes index on success
				return redirect()->route('admin.themes.index')->with('success', $module['name'] . ' theme has been updated!');
		}

		public function updatePlugin($id)
		{
				$client = new Client();

				// get the plugin
				$res = $client->request('GET', $this->apiUrl . '/module/' . $id, [ 'http_errors' => false ]);

				if ($res->getStatusCode() == 200) {
					$module = json_decode($res->getBody(), true);
				} else {
					abort(404);
				}

				// set slug
				$slug = ucfirst($module['slug']);

				// download the latest version
				$res = $client->request('GET', $module['latest_version']['download_url']);

				if ($res->getStatusCode() == 200) {
					$filename = $module['slug'] . '.zip';

					Storage::disk('plugins')->put($filename, $res->getBody(), 'public');
				} else {
					abort(404);
				}

				// then attempt to extract contents
				$path = base_path() . '/app/Modules/' . $filename;

				Zipper::make($path)->extractTo(base_path() . '/app/Modules');

				// once we've gotten the files all setup
				// lets run the upgrade event with the version #, if it exists
				$this->fireEvent($slug, $slug . 'Update', $module['latest_version']['version']);

				// we'll return to the plugins index on success
				return redirect()->route('admin.plugins.index')->with('success', $module['name'] . ' plugin has been updated!');
		}

		public function upgrade($type)
		{
				// get the current version data
				if (!Cache::has('cms_current_version')) {
						Cache::forget('core_cms_updates');

						$this->checkForCmsUpdates();
				}

				$updates_count = Cache::get('cms_updates');
				if ($type == 'normal') {
						$upgrade_version = json_decode(Cache::get('cms_latest_version'), true);
				} else {
						$upgrade_version = json_decode(Cache::get('cms_current_version'), true);
				}

				// get the contents of the ZIP file
				$client = new Client();

				$res = $client->request('GET', $upgrade_version['download_url']);

				if ($res->getStatusCode() == 200) {
						$zip_contents = (string) $res->getBody();
				} else {
						abort(404);
				}

				$zip_filename = $upgrade_version['branch_slug'] . '.zip';

				// create the ZIP locally
				Storage::disk('local')->put($zip_filename, $zip_contents, 'public');

				// open the ZIP file
				$zipPath = storage_path('app') . '/' . $zip_filename;

				// extract zip files to storage folder
				Zipper::make($zipPath)->extractTo(storage_path('app') . '/upgrade');

				$folder_slug = 'charliepage7-adaptcms-' . substr($upgrade_version['commit_hash'], 0, 12);

				// first, let's delete any previous upgrade
				$commits = Storage::disk('local')->directories('upgrade');

				foreach($commits as $commit) {
						if ($commit != 'upgrade/' . $folder_slug) {
								Storage::disk('local')->deleteDirectory($commit);
						}
				}

				// then get an array of files to process, from the ZIP
				$files = Storage::disk('local')->allFiles('upgrade/' . $folder_slug);
				foreach($files as $file) {
						$ignore_file = false;

						// TEMPORARY. going away before final 4.0 release
						// if filename ends with x variable, file gets skipped
						$endsWithArray = [
								'~',
								'.orig',
								'#',
								'public/files'
						];

						foreach($endsWithArray as $endsWith) {
								if (Core::endsWith($file, $endsWith)) {
										$ignore_file = true;

										break;
								}
						}

						// TEMPORARY. probably going away before final 4.0 release
						$ignorePaths = [
								'.env',
								'.gitignore',
								'tests/',
								'public/bower_components/',
								'config/database.php',
								'themes/',
								'Database/Seeds/',
								'app/Modules/Core/Http/Controllers/AdminUpdatesController.php'
						];

						foreach($ignorePaths as $ignorePath) {
								if (strstr($file, $ignorePath)) {
										$ignore_file = true;

										break;
								}
						}

						// can't do link file
						if (is_link($file)) {
								$ignore_file = true;
						}

						// if not bleeding edge, let's not sync all the modules
						if ($type == 'normal' && !empty($upgrade_version['core_modules'])) {
								foreach($upgrade_version['core_modules'] as $module) {
										if (strstr($file, 'app/Modules/' . $module)) {
												$ignore_file = true;

												break;
										}
								}
						}

						if ($ignore_file) {
								continue;
						}

						// so we're good to go on!
						// let's extract the relative path
						$relative_path = str_replace('upgrade/' . $folder_slug, '', $file);

						// if the file doesn't exist locally,
						// write it
						if (!Storage::disk('base')->exists($relative_path)) {
								$contents = Storage::disk('local')->get($file);

								Storage::disk('base')->put($relative_path, $contents, 'public');
						} else {
								// time to match file sizes
								$upgrade_size = Storage::disk('local')->size($file);
								$current_size = Storage::disk('base')->size($relative_path);

								// if any different, we update
								if ($upgrade_size != $current_size) {
										$contents = Storage::disk('local')->get($file);

										Storage::disk('base')->put($relative_path, $contents, 'public');
								}
						}
				}

				// now that we're done, let's cleanup
				Storage::disk('local')->delete($zip_filename);

				// and lastly, set the latest commit hash and commit version data
				$this->setCommitHash($upgrade_version['commit_hash']);
				Cache::forever('cms_current_version', json_encode($upgrade_version));

				if ($type == 'bleeding_edge') {
						$updates_count--;
				}

				Cache::forget('cms_latest_version');
				Cache::forget('cms_latest_version_name');

				// decrement update notifications
				$updates_count--;

				// clear the cache
				Core::clearCache();

				// reset bleeding edge
				if ($type == 'bleeding_edge') {
						Cache::forever('bleedinge_edge_update', 0);

						$updates_count--;
				}

				Cache::forever('cms_updates', $updates_count);

				$current_version = $upgrade_version;

				// run artisan
				try {
            $status = Artisan::call('vendor:publish');
        } catch(\Exception $e) {
            abort(500, 'Cannot publish module assets. Try chmodding the /public/assets/modules/ folder to 755 recursively.');
        }

				return view('core::UpdatesAdmin/upgrade', compact('upgrade_version', 'current_version'));
		}
}