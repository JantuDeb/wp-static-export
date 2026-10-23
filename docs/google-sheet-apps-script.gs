/**
 * Receives form submissions from the static site and appends them as rows to a Google Sheet.
 *
 * Setup:
 *  1. Create a Google Sheet. Extensions > Apps Script. Paste this file.
 *  2. Deploy > New deployment > type "Web app".
 *     Execute as: Me.  Who has access: Anyone.
 *  3. Copy the web app URL (ends in /exec) into the form rule's "URL / ID / address" field,
 *     with "Send to" set to "Google Sheet (Apps Script)".
 *
 * The first submission creates the header row. New fields add new columns automatically.
 * Fields starting with "_" (_page, _submitted) are metadata sent by the exporter.
 */
function doPost(e) {
  var lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try {
    var data = JSON.parse(e.postData.contents);
    var sheet = SpreadsheetApp.getActiveSpreadsheet().getSheets()[0];
    var lastCol = Math.max(sheet.getLastColumn(), 1);
    var headers = sheet.getRange(1, 1, 1, lastCol).getValues()[0].filter(String);

    Object.keys(data).forEach(function (key) {
      if (headers.indexOf(key) === -1) headers.push(key);
    });
    sheet.getRange(1, 1, 1, headers.length).setValues([headers]);

    var row = headers.map(function (h) {
      var v = data[h];
      // Prefix formulas so a visitor cannot inject =IMPORTXML(...) into your sheet.
      return typeof v === 'string' && /^[=+\-@]/.test(v) ? "'" + v : v === undefined ? '' : v;
    });
    sheet.appendRow(row);
    return ContentService.createTextOutput(JSON.stringify({ ok: true })).setMimeType(ContentService.MimeType.JSON);
  } finally {
    lock.releaseLock();
  }
}
