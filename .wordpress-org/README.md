# WordPress.org Plugin Assets

This directory contains assets for the WordPress.org plugin repository.

## Required Assets

### Banner Images
- `banner-772x250.png` - Low resolution banner (required)
- `banner-1544x500.png` - High resolution banner (recommended for retina displays)

### Icon Images
- `icon-128x128.png` - Low resolution icon (required)
- `icon-256x256.png` - High resolution icon (recommended for retina displays)

### Screenshots
Screenshots referenced in readme.txt should be placed here:
- `screenshot-1.png` - Plugin settings page with connection status
- `screenshot-2.png` - Order sync status metabox on order edit page
- `screenshot-3.png` - Bulk sync interface with date range selection
- `screenshot-4.png` - Sync log viewer with filtering options
- `screenshot-5.png` - Reconciliation report with discrepancy detection

## Asset Guidelines

### Banner
- Dimensions: 772x250 pixels (1544x500 for retina)
- Format: PNG or JPG
- Keep text minimal, focus on branding
- Avoid transparency

### Icon
- Dimensions: 128x128 pixels (256x256 for retina)
- Format: PNG
- Simple, recognizable design
- Works well at small sizes

### Screenshots
- Capture actual plugin functionality
- Use clean, representative data
- Crop to relevant UI areas
- PNG format recommended

## Deployment

These assets are automatically deployed to WordPress.org SVN when:
1. A new release is created on GitHub
2. The deploy workflow copies assets to the SVN `/assets` directory

Assets in this directory are NOT included in the plugin ZIP file.
