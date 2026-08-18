# WP Stateless Azure

A minimal [wp-stateless](https://github.com/udx/wp-stateless), native to Azure Blob Storage — the same behavior model (modes, URL/srcset rewrite, delete mirroring, bulk sync) with **zero vendor libraries**. Auth is hand-rolled SharedKey against the Blob REST API; the whole plugin is one file.

Uploads land in Azure Blob Storage and are served from the blob endpoint (or your CDN in front of it). WordPress keeps its normal local-file flow; hosts stay disposable.

## Install

- **Regular plugin:** copy `wp-stateless-azure.php` into `wp-content/plugins/wp-stateless-azure/` (git clone this repo, or grab a release zip) and activate.
- **Must-use plugin:** drop the file into `wp-content/mu-plugins/`. No activation step.
- **On [Cloud WP](https://github.com/udx/cloud-wp):** don't install anything by hand — site → Storage → *Offload to Azure Blob* provisions the storage account and container in your subscription, writes the credentials into the site's Secret, and the plugin is already in the runtime image.

## Configuration

Environment variables win, then wp-config constants of the same name:

| Setting | Purpose |
| --- | --- |
| `WP_MEDIA_ACCOUNT` | storage account name |
| `WP_MEDIA_CONTAINER` | public-read container |
| `WP_MEDIA_SECRET` | a storage account key |
| `WP_MEDIA_BASE_URL` | optional served-URL override (CDN / Front Door in front of the container) |
| `WP_MEDIA_MODE` | `disabled` \| `backup` \| `cdn` \| `ephemeral` (default `cdn`) |
| `WP_DISABLE_MEDIA_OFFLOAD` | any truthy value parks the engine entirely |

## Modes

- **backup** — upload to Blob, keep local files, keep serving *local* URLs.
- **cdn** *(default)* — upload, keep local files, serve *Blob* URLs.
- **ephemeral** — upload, **delete the local files**, serve Blob URLs. Thumbnail-regeneration style operations need the local file back, which this plugin does not re-download — that is the mode's deal.

The mode is also selectable under *Settings → WP Stateless Azure* unless `WP_MEDIA_MODE` pins it.

## Bulk sync

Pre-existing media doesn't move on its own — sync it:

- wp-admin: *Settings → WP Stateless Azure → Sync existing media now* (batched, resumable).
- WP-CLI: `wp stateless-azure sync [--batch=100]`.

## Behavior notes

- URL rewriting is post-meta gated: only attachments whose upload actually landed in Blob serve from the blob endpoint. If Azure is unreachable the upload just logs an error and the local file keeps serving — an upload never breaks because of an offload failure.
- Deleting an attachment deletes its blobs (best effort).
- v1 buffers each whole file in memory for the PUT: images are small; giant video uploads are the documented limit.

## License

GPL-2.0-or-later. © UDX.
