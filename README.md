# Falcon

![](https://38.media.tumblr.com/73f719a60ed916ff6790f5ebe6b96d24/tumblr_mzvqty8GWZ1rpe379o1_500.gif)

For a long time, WordPress has had a pretty dysfunctional relationship with
email. The Post by Email feature was neglected, then finally reinvigorated and
moved to a plugin. Notifications for comments isn't the greatest, and
notifications for new posts is non-existent.

This is annoying for most sites, but makes internal communication sites like P2
super annoying to use.

You know what has great email notifications? GitHub. They stay out of your way,
integrate perfectly with email clients, and are just generally kick-ass.

You know what **now** has great email notifications? WordPress.

![](http://i.imgur.com/RNY5qQj.png)


## Neato! How do I set this up?

Two steps!

### Step 1: Pick an email provider

You'll need to pick an email provider that we support. Right now, we have
handlers for [Mandrill][] and [Postmark][]. If you haven't got one already, I'd
personally recommend Postmark, as setting up is a wee bit easier.

Set aside a domain to handle emails. `notifications.example.com` is a good
example. We'll be sending emails from `reply@` from this domain, and you'll be
replying to the same email (but with a plus address bit for authentication).

(Wish we had support for another handler? Let us know, or try making a handler
yourself! You'll find the code for it in `library/Falcon/Handler`)

[Mandrill]: https://mandrill.com/
[Postmark]: https://postmarkapp.com/

### Step 2: Install and set up the plugin

Clone down the plugin from GitHub and drop it in to your WP plugins directory.
Enable it on your site, then head to the settings page and set your preferences.
If you're following the steps from above, set both your Reply-To and From email
address to `reply@notifications.example.com` (obviously with your domain
instead).

Pick your email handler and follow the guide to set it up.

You're done! Welcome to a new world of WP.


## Hey, I have some questions...

### What if I use multisite?

Wow, do we have a treat for you? Falcon is built from the ground up to work
beautifully with multisite. There's just one trick: you'll need to Network
Activate it. Doing this will put Falcon into "network mode", and activate some
special tools just for you.

First up, head to your Network Admin, then to the Falcon settings page. You'll
notice that you can enable or disable Falcon per-site, so go ahead and do that
now. Keep in mind that any new sites will need to be turned on here when you
add them.

Once you've saved this, head over to your profile page to set your own settings.
If you're running in network mode, you'll notice Falcon's settings have been
superpowered with a grid that looks something like this:

![](http://i.imgur.com/rQlThgE.png)

Sweet, huh?

Emails will always be sent from the network-wide email address, with the same
applying to your reply-to address. However, Falcon will use each site's data
when sending emails and handling replies, so no need to worry about conflicts.

**Privacy:** Users will only ever receive notifications for sites they're able
to access. If you have several levels of users on your network, rest assured
that Falcon won't expose any secrets.


## Cool, but what's the low-down on the internals?

Falcon is built essentially to be a facilitator of communication. Internally,
Falcon has two sides:

* **Connectors**: These connect events on your site (like posts being published
  or comments being posted) to Falcon, and turn the events into emails to be
  sent to the users.
* **Handlers**: These take emails prepared by Connectors and send them off to
  email providers (like Mandrill or Postmark).

The same then applies in reverse when receiving emails. Handlers are responsible
for parsing out incoming emails and passing them to Falcon, then Falcon passes
these off to the relevant Connectors to add into the system.
