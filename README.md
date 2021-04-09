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


