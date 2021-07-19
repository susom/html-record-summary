# HTML Record Summary
The goal of this project is to build HTML summaries that can be
- converted into PDFs
- rendered on pages



1. Create your cloud function
1. Create a service account
1. Create a user role that contains (missing the token creator killed me for hours - also, I was unable to find an acceptable pre-made role so I made a new role by permissions):
  - cloudfunctions.invoker and
  - iam.serviceAccountTokenCreator
1. Export the private key for the service account


## To set up locally
1. In server settings, add entries to
   * GCP cloud function
   * GCP Key
2. ngrok local redcap
   > ngrok http 80
3. In project settings, add entries:
   * Replace Base URL: ngrok forwarding URL
     (ex: '
     http://11a1a1a11a11.ngrok.io/' Don't forget the trailing slash!)

