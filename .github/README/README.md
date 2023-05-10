# Gthub Actions Workflows for ZotPrime updates.

- Link to the main project: https://github.com/uniuuu/zotprime (zotprime).
- Link to to the subproject used as submodule for main one: https://github.com/uniuuu/zotero-build  (client/zotero-build).
- Link to the README file: https://github.com/uniuuu/zotprime/blob/new_dev/.github/README.md .

1. The flow is starting via defined schedule in workflow ```CI_upstream_sync.yml``` under ```client/zotero-build``` repository. It's meant to check whether there are new commits on upstream zotero/zotero-build and in case yes the upstream will be merged into master and test branch of the ```uniuuu/zotero-build``` . Note, upstream ```zotero/zotero-build``` doesn't use tags and releases but commits only.

2. Next workflow ```CI_push_to_zp_if_tag.yml``` under ```client/zotero-build``` repository is starting right after merge to test branch. It does Unit Test and in case success it merges test branch into stage branch and sends notification to Slack. In case of failure it sends notification to Slack and email.

3. Operator receives Slack message and troubleshoots in case of UT failure or in case of UT success performs analisys of latest merged commits from upstream. Based on manual job decigion is made whether latest commit is path, minor or major change. Respective tag is being issued to stage branch of ```client/zotero-build```.

4. Once tag has been pushed it triggers ```CI_push_to_zp_if_tag.yml``` under ```client/zotero-build``` . Algorytm checks latest tag semver and it triggers main (zotprime) repository's workflow and passes message to it storing in variable the semver change (path, minor, major) label.

5. The workflow ```update-sub-zotero-build.yml``` of the zotprime repository (new_dev branch) receives semver label and checkouts zotprime repository. At next step it does specific submodule ```client/zotero-build``` udpate (total 6 of first level submodules with a number of sub-submodules) ```git submodule update --init --recursive --remote client/zotero-build``` 
It commits changes with commit message either fix, feat or BREAKING CHANGE based on a respective label received after parsing semver in previous workflow. Next step it's issuing a tag again based on a respective label received after parsing semver in previous workflow

6. Once commit done to new_dev branch it triggers unit test for Zotero client. The ```client-build-test.yml``` builds Zotero client via Docker image. 

7. Same strategy is under development for the rest of 5 first level submodules. ZotPrime consist of Server (based on multiple services) first 3 submodules and monolithic the rest of 3 first level submodules. Once there is update either client or server final testing have to beperformed with both server and client.
