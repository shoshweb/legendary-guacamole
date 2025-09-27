# Legal Document Automation - Version History

## Version 2.6.1 - September 26, 2025
### Fixed
- **CRITICAL FIX**: Merge tag replacement now working properly
- Fixed split merge tags being reconstructed before processing
- Improved merge tag processing to handle both `{$TAG}` and `{&#36;TAG}` formats
- Enhanced merge tag replacement with modifier support
- Use-your-Drive integration confirmed working ✅

### Technical Improvements
- Streamlined merge tag replacement logic
- Better error handling in DOCX processing
- Reduced complexity in XML processing
- Fixed LSP diagnostics and code quality issues

## Version 2.6.0 - Base Working Version
### Features
- Legal document automation with DOCX templates
- Gravity Forms integration for form submissions
- Email notifications with document attachments
- Google Drive integration via Use-your-Drive plugin
- Admin interface for template and field management
- Comprehensive logging system

### Dependencies
- WordPress 5.0+
- PHP 7.4+
- Gravity Forms plugin
- Use-your-Drive plugin (for Google Drive functionality)
- PHP ZIP extension

## Known Issues (Resolved in 2.6.1)
- ~~Merge tags were not being replaced due to XML splitting~~
- ~~Template processing showed "0 replacements made"~~

## Testing Results
✅ Use-your-Drive integration working correctly
✅ Documents uploaded to correct Google Drive folders
✅ Email notifications with attachments working
✅ Plugin loads without fatal errors
🔧 Merge tag replacement fixed in v2.6.1