# Symfony 2 Console AutoComplete Component

### About
Although there's a very good autocomplete component as of Symfony2.2, I wanted
mine to work a little differently. You see, most autocomplete components only
support prefix searching. So, I have to know the name of the thing I want to
autocomplete before I start. 

This component supports in-string searching, so you don't have to know what
the desired result starts with. This presents other interesting complications
with the UI - how do you show what phrase has matched, if it's in the middle
of the string?

This autocomplete component also returns the key of the matched value (from the
array of autocomplete options) rather than the matched value itself. 

So, thank you to Symfony2 for the inspiration. A lot of the original code from 
DialogHelper appears in this class because the private scoping of certain 
functions meant I couldn't easily extend DialogHelper. Hopefully, by making
this component publicly available, I will get absolution :)

### How it works
Let's say you've got three autocomplete options:
- 'a' => Apples
- 'b' => Bananas
- 'c' => Carrots

If you type "rot", the auto suggestion will displayed something like:
Your prompt > Car**rot**s

If you hit return, you'll get 'c' returned, rather than 'Carrots'. 

### Example

coming soon
