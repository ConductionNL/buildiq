# Form library

The form library keeps forms your organisation can use again. A maker saves a
form page or a registration form to the library once, and any app can then use
it. Other organisations pick a form up as a file or from GitHub.

A library form holds the form and nothing else: its fields, steps, logic,
presets and the text people read after sending, plus the definitions of the
schema properties its fields write to. It never holds records.

## Save a form to the library

1. Open the form page in the page designer, or open a registration form in the
   case type's form list.
2. Choose **Save to form library**.
3. Give the form a name, pick one of the four categories (the same ones the app
   store uses for templates) and fill in the publisher.
4. Save.

Buildiq reads the schema the form saves into first. When a field writes to a
property that schema does not have, the save is refused and the property is
named, so a broken form never reaches the library. Pick the schema the form
saves into before you save it.

## Find a form

Open the app store and choose **Forms**, next to **Templates** and **Blocks**.
The view lists:

- the forms in your library, with their category and publisher;
- forms published on GitHub in repositories with the topic `buildiq-form`.

Search by name, description or publisher, and narrow the list with the category
filter. The category stays in the link (`?category=`), so a colleague who opens
the link sees the same list.

## Use a form in an app

Choose **Use this form** on a library form. The dialog shows what the form asks
and then lets you pick:

- the app and the version to add it to;
- what to add: a new form page, or a registration form for a case type (give
  the property that holds the case type and the case type itself);
- the schema the form saves into. This maps the form onto one of the app's
  schemas.

When that schema lacks properties the form needs, the dialog lists them. Tick
**Add these properties** to add them from the definitions the library form
carries. Nothing is written until you choose **Add to the app**. A registration
form arrives as a draft, so you can check it before it goes live.

## Request several products in one form

A resident can ask for several products in one go, for example a parking
permit and a waste container, and each product starts its own process.

1. In the form builder, add a **Product list** field. Enter the products a
   resident can choose, the details asked for each product, and the most rows
   one submission may hold (at most 25).
2. Use the form on a step of a journey. In the journey designer, open the
   writes of the last step and choose **Once per item of** the product list.
3. Enter the item field that picks the target (usually `product`) and add a
   target for every product: the register, the schema and the type value its
   request is written with. Map `item.<detail>` to the fields of the request.
4. To keep the requests together, mark an earlier write as the one the parts
   hang under, and fix a field of the request to the id of that write.

The designer refuses to save while a product has no target, or while the
mapping writes a field one of the target schemas does not have. It names the
product and the field. Next to each target it shows the processes that start
when a request is created there, and "This product starts no process." when
there are none. That is a warning: a product may start its process another
way.

Until OpenRegister runs repeating writes and the product list widget is
available, a journey with a repeating write can be designed and saved but not
submitted.

## Share forms with other organisations

### As a file

**Export** on a library form downloads a JSON file with the envelope
`kind: "form-template"` and a schema version. In the other organisation, choose
**Import a form** in the **Forms** view and pick the file. Buildiq checks the
envelope and the form before it adds anything: a file of another kind is
refused with "This file is not a form export.", and nothing is created.

### Through GitHub

Publish the exported file as `form.json` at the root of a GitHub repository and
give the repository the topic `buildiq-form`. Every Buildiq that browses the
**Forms** view finds it there, and **Add to my library** creates a local library
form from it. Using it is then the same as any library form.

## Good to know

- A shared form can carry a confirmation text or presets that do not fit your
  organisation. Read what the dialog shows before you add it.
- Buildiq does not moderate what others publish on GitHub.
- Library forms follow OpenRegister's access rules on the `form-template`
  schema in the `buildiq` register: everyone signed in can read them, and
  administrators can create and change them.
