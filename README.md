# bbPress Reply by Email

Now your forum's participants can reply to topics via email (thanks to the [Postmark](http://postmarkapp.com) inbound mail server).

## Cloning the Repository
This repository uses submodules. When using submodules, `git clone` and `git pull` will not gather all the files and resources of the repository.

To clone the entire repository, you need to include the `--recursive` flag.

```
git clone --recursive git@github.rmccue/bbPress-Reply-by-Email.git
```

If you cloned without the `--recursive` flag, never fear, you can achieve a similar result with the commands:

```
git submodule init
git submodule update
```