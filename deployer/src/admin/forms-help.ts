/** Plain-language guidance for each form destination, shown next to the form rules. */

export interface FormProvider {
  id: string;
  title: string;
  tag?: string;
  blurb: string;
  endpointLabel: string;
  placeholder: string;
  endpointHelp: string;
  /** Only Google Forms needs every field mapped to an entry id. */
  showMap?: boolean;
  steps: string[];
  code?: { label: string; text: string };
  caveat?: string;
}

export const APPS_SCRIPT = `function doPost(e) {
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
  var data = JSON.parse(e.postData.contents);
  var width = Math.max(sheet.getLastColumn(), 1);
  var headers = sheet.getLastRow() === 0 ? [] : sheet.getRange(1, 1, 1, width).getValues()[0];
  Object.keys(data).forEach(function (key) {
    if (headers.indexOf(key) === -1) headers.push(key);
  });
  sheet.getRange(1, 1, 1, headers.length).setValues([headers]);
  sheet.appendRow(headers.map(function (h) { return data[h] === undefined ? '' : data[h]; }));
  return ContentService.createTextOutput('ok');
}`;

export const FORM_PROVIDERS: FormProvider[] = [
  {
    id: 'google-sheet',
    title: 'Google Sheet',
    tag: 'Free, recommended',
    blurb: 'Every submission becomes a new row in a spreadsheet you own.',
    endpointLabel: 'Web app URL',
    placeholder: 'https://script.google.com/macros/s/…/exec',
    endpointHelp: 'The address Google gives you after step 6 below.',
    steps: [
      'Create a new Google Sheet (sheets.new). Name it anything, for example "Website messages".',
      'In the sheet open Extensions → Apps Script and delete the sample code.',
      'Paste the script below (use the Copy button) and press Save.',
      'Press Deploy → New deployment. Click the gear icon and choose "Web app".',
      'Set "Execute as" to Me and "Who has access" to Anyone, then press Deploy and approve the permission prompts.',
      'Copy the "Web app URL" and paste it into the box above. Save changes, then export again.',
      'Columns are created from your form field names the first time someone submits.',
    ],
    code: { label: 'Apps Script to paste', text: APPS_SCRIPT },
    caveat: 'After you change the script later, use Deploy → Manage deployments → Edit → New version, or the old code keeps running.',
  },
  {
    id: 'formspree',
    title: 'Email (Formspree)',
    tag: 'Easiest for email',
    blurb: 'Formspree emails each submission to you. The free plan covers small sites.',
    endpointLabel: 'Formspree form URL or ID',
    placeholder: 'https://formspree.io/f/abcdwxyz',
    endpointHelp: 'Looks like https://formspree.io/f/abcdwxyz. Pasting only abcdwxyz also works.',
    steps: [
      'Create a free account at formspree.io and press "New form".',
      'Enter the email address that should receive messages and create the form.',
      'Copy the form endpoint (https://formspree.io/f/…) and paste it into the box above.',
      'Save changes and export again. Formspree asks you to confirm the first submission by email.',
    ],
  },
  {
    id: 'google-form',
    title: 'Google Form',
    blurb: 'Send answers into a Google Form. More work to set up than a Sheet because each field must be matched.',
    endpointLabel: 'Form link',
    placeholder: 'https://docs.google.com/forms/d/e/…/viewform',
    endpointHelp: 'The normal link you share for the form (it ends in /viewform).',
    showMap: true,
    steps: [
      'Create a Google Form with one question for each field of your website form (for example Name, Email, Message).',
      'Open the form, press the three dots (⋮) → "Get pre-filled link". Type a sample answer into every question and press "Get link", then copy the link.',
      'Look at that link: each question appears as entry.123456789=sample. Note each number.',
      'Paste the normal form link (the one ending in /viewform) in the box above.',
      'In the field map below, write one line per field: your website form field name, an equals sign, and its entry id. Example: your-email=entry.123456789',
      'The website field name is the "name" attribute of the input (in Contact Form 7 it is the name inside the [text your-name] tag).',
      'Fields without a line are not sent. Save changes and export again.',
    ],
    caveat: 'The visitor sees your success message, but Google does not tell the page whether it worked, so submit a test and check the Responses tab.',
  },
  {
    id: 'netlify',
    title: 'Netlify Forms',
    blurb: 'Only for sites hosted on Netlify. Netlify collects submissions in its dashboard.',
    endpointLabel: 'Form name',
    placeholder: 'contact',
    endpointHelp: 'Any short name. It appears in Netlify under Forms.',
    steps: [
      'Deliver the site to Netlify (Deployment tab).',
      'In Netlify open Forms and turn on form detection if it is off.',
      'Export and deploy again, then submit a test message and check the Forms page.',
    ],
    caveat: 'Does not work on Cloudflare, S3 or other hosts.',
  },
  {
    id: 'webhook',
    title: 'Webhook (advanced)',
    blurb: 'Sends the submission as JSON to any URL, such as Zapier, Make, n8n or your own API.',
    endpointLabel: 'Webhook URL',
    placeholder: 'https://hooks.zapier.com/hooks/catch/…',
    endpointHelp: 'The server must allow cross-origin requests from your site.',
    steps: [
      'Create a webhook trigger in your automation tool and copy its URL.',
      'Paste it in the box above. The body is JSON: { form, page, submitted, fields: { name: value } }.',
    ],
  },
  {
    id: 'mailto',
    title: 'Visitor’s email app',
    blurb: 'Opens the visitor’s own email program with the message filled in. No account needed.',
    endpointLabel: 'Your email address',
    placeholder: 'you@example.com',
    endpointHelp: 'Where the visitor’s email will be addressed.',
    steps: ['Enter your email address and save.'],
    caveat: 'Visitors must press Send in their email app, many will not have one set up, and nothing is stored. Prefer Formspree or a Google Sheet.',
  },
];

export const providerById = (id: string): FormProvider => FORM_PROVIDERS.find((p) => p.id === id) ?? (FORM_PROVIDERS[0] as FormProvider);
