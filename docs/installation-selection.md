# Installation modules and themes

Workspace YAML (`config/app.yaml`, optional workspace overrides and `config/modules.yaml`) seeds `enabled_modules`, `active_theme` and `admin_theme` for a new installation. Run the `site` migration explicitly before changing these selections:

```sh
APP_WORKSPACE=development php bin/console database:migrate --yes
```

The `site` module stores the chosen module ID list and public/admin theme IDs in three `config` rows keyed by workspace. Manifests remain the source of module dependencies, theme ancestry and libraries. The core reads a validated snapshot at `storage/cache/installation/{workspace}.json` before constructing services and overlays those three keys in the effective ConfigManager. It does not connect to the database during bootstrap. A selection write updates the database and atomically replaces that snapshot. A new Core or PHP request sees the new choice; the current request keeps its existing container. Never edit the snapshot manually. Workspaces sharing one connection still have separate rows and snapshots.

An administrator with `site.extensions.view` can inspect discovered modules at `/admin/modules`; `site.extensions.edit` allows enabling/disabling them. `site.themes.view` and `site.themes.edit` control `/admin/themes`. POST actions use CSRF protection and repeat permission checks in the service. The `site` and `admin` modules cannot be disabled through this interface; other enabled modules cannot be disabled while a dependent module needs them. Disabling never removes tables, files or content. The public and admin themes are selected independently. Missing themes, invalid parent chains, unavailable libraries and invalid module dependency graphs are rejected before saving.

In this slice, an installed extension means its validated manifest and files are present in a discovery root. The administration does not download, install or uninstall packages, and it does not run extension lifecycle code.

CLI commands require `APP_WORKSPACE` or `--workspace`:

```sh
APP_WORKSPACE=development php bin/console module:list
APP_WORKSPACE=development php bin/console module:migrate example --yes
APP_WORKSPACE=development php bin/console module:enable example
APP_WORKSPACE=development php bin/console module:disable example
APP_WORKSPACE=development php bin/console theme:select public default
APP_WORKSPACE=development php bin/console theme:select admin admin_theme
APP_WORKSPACE=development php bin/console installation:refresh
```

Before enabling a module, inspect its code, declared dependencies, DB mappings and library assets. Enable its required modules first. `module:enable` rejects pending migrations; `module:migrate <id> --yes` explicitly migrates the candidate module and any pending migrations on its configured connections without enabling it. Review the pending plan and back up important data before running schema changes. Publish declared assets separately using `module:publish-assets` or `theme:publish-assets`. HTTP enablement never executes migrations, installation hooks or asset publication. Migrating a module does not itself enable it.

If a selected admin theme or module prevents the admin from booting, run:

```sh
APP_WORKSPACE=development php bin/console installation:recover
```

This removes only the snapshot and makes the next boot use the workspace YAML seed. It does not delete the persisted database selection or data. To replace broken rows with that validated YAML seed, run `installation:reset --yes` when the database is available. Then `theme:select` and module commands can make further changes. Normal edits reject stale rows so an old request cannot silently overwrite another operator's selection. If database rows were repaired out of band, `installation:refresh` validates them and rebuilds the snapshot without changing them. A partial or invalid row set is rejected. Loss of an existing snapshot also requires refresh if database choices should take precedence over YAML. `help` and a new YAML-seeded boot work before the table is installed. Database failures after installation remain visible to operations that require the database.

Selections in the database are not secret. Connection definitions, credentials, workspaces and other YAML settings remain deployment configuration. The snapshot is an implementation cache of the database-owned selection, not a second editable source. See [ADR 0015](decisions/0015-persisted-installation-selection.md).
