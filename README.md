# Statuspage Manager for GCP Monitoring

![Graphics showing configuration options](./docs/explanatory_graphics.png)

One picture is often worth thousand words. This repo gives you an easy-to-deploy cloud function which can be triggered when Google Cloud Monitoring Policy fires and it can automatically update your Atlassian Statuspage.

The cloud function makes it possible to automatically open Statuspage incidents when Policy fires and automatically resolve the incident when things go back to normal in GCP. This works really well with [Uptime checks](https://cloud.google.com/monitoring/uptime-checks/introduction), but can be used with any other [policy](https://cloud.google.com/monitoring/alerts/using-alerting-ui).

Using the Policy's user labels and Documentation you can configure all the important properties of the incident that's to be created in Statuspage. You can configure:

- **Name of the incident**
  - `<public-name></public-name>` tag in policy documentation
- **Text of the update when the Statuspage incident starts**
  - `<public-start-info></public-start-info>` tag in policy documentation
- **Text of the update when the Statuspage incidents ends**
  - `<public-end-info></public-end-info>` tag in policy documentation
- **Impact of the incident**
  - `none`, `maintenance`, `minor`, `major`, `critical`
  - value to override calculated impact value
  - [ℹ️ see Statuspage docs](https://developer.statuspage.io/#operation/postPagesPageIdIncidents)
- **Affected components**
  - List one or multiple Statuspage Component IDs
  - Use `__` (two underscores) to separate multiple components
  - You can find Statuspage Component ID at the bottom of its edit form
- **Status of the components**
  - `operational`, `under_maintenance`, `degraded_performance`, `partial_outage`, `major_outage`
  - Default: `major_outage`
  - [ℹ️ see Statuspage docs](https://developer.statuspage.io/#operation/postPagesPageIdComponents) 
- **Whether to send notifications**
  - `true`, `false`
  - Default: `true`

## Quick Start
This assumes that you have your Statuspage setup and your monitoring policies in place. Then, these are the few quick things that you need to do to set this up. Setup GCP PubSub topic, Deploy the Cloud Function, Create Notification Channel, and configure monitoring policy. Sounds like a lot, but it can be done in just a few minutes. If you have `gcloud` [set up](https://cloud.google.com/sdk/gcloud#download_and_install_the) you can do this form your terminal, otherwise you can use the [Google Cloud Console Web UI](https://cloud.google.com/cloud-console).

### Setup PubSub Topic
```bash
gcloud pubsub topics create YOUR_TOPIC_NAME
```

Good topic name can be for example `statuspage-manager-alerting`

### Deploy the Cloud Function
Clone this repo. Get you Statuspage ID and generate an Auth token by signing in to your Statuspage account, clicking on your profile picture in the top right and clicking **API Info**. On this page you'll find you Page ID and you can generate a new API Key. 

Then run the following commands from the root of the cloned directory:

```bash
STATUSPAGE_PAGE_ID='YOUR_PAGE_ID'
STATUSPAGE_PAGE_ID='YOUR_AUTH_TOKEN'

gcloud beta functions deploy YOUR_FUNCTION_NAME \
      --gen2 \
      --region=YOUR_REGION \
      --source=./src/ \
      --runtime='php81' \
      --ingress-settings="internal-only" \
      --memory='512MB' \
      --timeout='60s' \
      --max-instances='5' \
      --entry-point='main' \
      --trigger-topic=YOUR_TOPIC_NAME \
      --set-env-vars=PAGE_ID=$STATUSPAGE_PAGE_ID,AUTH_TOKEN=$STATUSPAGE_AUTH_TOKEN
```
- YOUR_FUNCTION_NAME e.g. `statuspage-manager`
- YOUR_REGION e.g. `eu-west2`
- YOUR_TOPIC_NAME e.g. `statuspage-manager-alerting`

If you bump into issues, [here](https://cloud.google.com/functions/docs/calling/pubsub) is a guide from Google that might help.

### Create Notification Channel

Go to GCP Console, Select Monitoring > Alerting and click [Edit Notification Channels](https://console.cloud.google.com/monitoring/alerting/notifications). At the very bottom add a new Pub/Sub. Fill in Channel display name e.g. `Status Page Manager Alert` and then the full topic name in the format `project/YOUR_PROJECT_ID/topics/YOUR_TOPIC_NAME`. You can send a test notification to check that you pick an existing topic.

[Here](https://cloud.google.com/blog/products/management-tools/how-to-use-pubsub-as-a-cloud-monitoring-notification-channel) is an article from Google Cloud on the topic of suing Pub/Sub as a notification channel in Cloud Monitoring.

### Configure Monitoring Policy
Say you want to create an incident whenever a uptime check policy triggers. Find the policy in Monitoring > [Alerting](https://console.cloud.google.com/monitoring/alerting), click `Edit`, select the Alert details > `Notifications and name`. In the notification channels dropdown find the channel you've created in the previous step and then (optionally) configure other parameters of the Statuspage using the Policy user labels and policy Documentation as shown at the start of this document.

### 🚀 That's it
Your Google Cloud Monitoring Alerts are now connected to you Atlassian Status page. Now let's just hope they will never be needed. 😉

### Contributing

This repo is published under XYZ license, so feel free to fork and improve.
You can also file an issue or get in touch with us at [support@snoofa.com](mailto:support@snoofa.com).
