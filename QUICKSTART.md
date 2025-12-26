# Quick Start Guide

Get the Files Labels UI up and running in minutes.

## Prerequisites

- Node.js (v20+ recommended)
- npm (v10+ recommended)
- Nextcloud 28+ instance

## Installation

### 1. Install Dependencies

```bash
npm install
```

### 2. Build the Frontend

```bash
npm run build
```

This creates `js/files_labels-main.js` which Nextcloud will load.

### 3. Deploy to Nextcloud

**Option A: Development with Docker**

```bash
# Start Nextcloud
podman-compose up -d

# Enable the app
podman exec -u www-data nextcloud-files-labels-nextcloud-1 php occ app:enable files_labels

# Access: http://localhost:8080
# Login: admin / admin
```

**Option B: Production Nextcloud**

```bash
# Copy app to Nextcloud apps directory
cp -r . /path/to/nextcloud/custom_apps/files_labels/

# Enable via web UI or CLI
sudo -u www-data php occ app:enable files_labels
```

## Using the UI

1. Open Nextcloud and go to the **Files** app
2. Click on any file to open the sidebar
3. Look for the **Labels** tab (tag icon)
4. Click to open and manage labels

## Development Workflow

### Watch Mode (Auto-rebuild)

```bash
npm run dev
```

Leave this running while developing. It will automatically rebuild when you change files.

### Testing Your Changes

1. Make changes to Vue components in `src/`
2. Wait for webpack to rebuild (or run `npm run build`)
3. Hard refresh your browser (Cmd+Shift+R or Ctrl+Shift+R)
4. Changes should appear immediately

### Debugging

**Check the Browser Console:**
```
Right-click → Inspect → Console
```

Look for errors or warnings related to files_labels.

**Check Nextcloud Logs:**
```bash
# Docker
podman exec nextcloud-files-labels-nextcloud-1 tail -f /var/www/html/data/nextcloud.log

# Production
tail -f /var/www/html/data/nextcloud.log
```

**Check the Build Output:**
```bash
ls -lh js/
```

You should see `files_labels-main.js` and related chunk files.

## Common Issues

### Tab doesn't appear

1. **Verify app is enabled:**
   ```bash
   php occ app:list | grep files_labels
   ```

2. **Check JS file exists:**
   ```bash
   ls js/files_labels-main.js
   ```

3. **Hard refresh browser** (clear cache)

4. **Check browser console** for JS errors

### API errors (403/404)

- Verify the user has access to the file
- Check file permissions
- Ensure the app is properly enabled

### Build errors

```bash
# Clean and rebuild
rm -rf node_modules package-lock.json js/
npm install
npm run build
```

## File Structure

```
src/
├── main.js                    # Entry point
└── views/
    └── LabelsSidebarTab.vue   # Main component

js/                            # Build output (auto-generated)
├── files_labels-main.js       # Main bundle
└── files_labels-*.js          # Code-split chunks

package.json                   # Dependencies
webpack.config.js              # Build config
```

## Making Changes

### Modify the UI

Edit `src/views/LabelsSidebarTab.vue`:
- Template: HTML structure
- Script: Vue logic and API calls
- Style: SCSS styling

### Add New Features

1. Edit the Vue component
2. Rebuild: `npm run build`
3. Refresh browser
4. Test thoroughly

### Update Dependencies

```bash
npm update
npm run build
```

## Resources

- [Vue.js 2 Guide](https://v2.vuejs.org/v2/guide/)
- [Nextcloud Vue Components](https://nextcloud-vue-components.netlify.app/)
- [Nextcloud App Development](https://docs.nextcloud.com/server/latest/developer_manual/)

## Next Steps

- Read [FRONTEND.md](FRONTEND.md) for detailed architecture
- See [TESTING.md](TESTING.md) for UI testing checklist
- Check [UI_IMPLEMENTATION.md](UI_IMPLEMENTATION.md) for implementation details
- Review [README.md](README.md) for API documentation

## Support

- Check browser console for errors
- Review Nextcloud logs
- Verify file permissions
- Test API endpoints manually with curl

## Production Checklist

Before deploying:

- [ ] Run `npm run build` (not `npm run dev`)
- [ ] Test in all target browsers
- [ ] Verify no console errors
- [ ] Test with real Nextcloud instance
- [ ] Check file permissions (644 for files, 755 for directories)
- [ ] Verify app signature (if required)
- [ ] Test with multiple users
- [ ] Verify performance with many labels

---

**Happy coding!** 🎉
